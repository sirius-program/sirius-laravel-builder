<?php

declare(strict_types=1);

use App\Actions\Entry\CreateAction;
use App\Actions\Entry\DeleteAction;
use App\Actions\Entry\ForceDeleteAction;
use App\Actions\Entry\RestoreAction;
use App\Actions\Entry\UpdateAction;
use App\Policies\ActionEntryPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Definitions\ModelDefinition;
use Sirius\LaravelBuilder\Definitions\OperationSelection;
use Sirius\LaravelBuilder\Definitions\ResourceDefinition;
use Sirius\LaravelBuilder\Generators\ArtifactWriter;
use Sirius\LaravelBuilder\Generators\ResourceArtifacts;
use Sirius\LaravelBuilder\Inference\FieldPlanner;
use Sirius\LaravelBuilder\Schema\SchemaInspector;
use Sirius\LaravelBuilder\Support\ActionInput;
use Sirius\LaravelBuilder\Support\ReadValues;
use Sirius\LaravelBuilder\Support\ResourceNamer;
use Tests\Fixtures\ActionEntry;
use Tests\Support\ArchitectureRules;
use Tests\Support\FieldFixtures;

it('executes generated actions with scoped keys, policy decisions and validated writes', function (): void {
    Schema::create('action_entries', static function (Blueprint $table): void {
        $table->uuid('entry_key')->primary();
        $table->string('name')->unique();
        $table->string('secret');
        $table->decimal('amount', 12, 2);
        $table->json('settings');
        $table->text('notes');
        $table->boolean('admin')->default(false);
        $table->softDeletes();
    });

    $model = new ActionEntry;
    $definition = new ModelDefinition(ActionEntry::class, 'ActionEntry', true);
    $identity = (new ResourceNamer)->identify($definition, $model->getTable(), 'Entry');
    $schema = app(SchemaInspector::class)->inspect($model);
    $plans = [];

    foreach ($schema->columns as $column) {
        $type = match ($column->name) {
            'name'     => FieldType::Text,
            'secret'   => FieldType::Password,
            'amount'   => $column->type === 'float' ? FieldType::Number : FieldType::Currency,
            'settings' => FieldType::Textarea,
            'notes'    => FieldType::Richtext,
            default    => null,
        };

        if ($type === null) {
            continue;
        }

        $plans[] = (new FieldPlanner)->plan($schema, $column, $type, cast: $model->getCasts()[$column->name] ?? null);
    }

    $resource = new ResourceDefinition($definition, $identity, new OperationSelection(true), fields: array_column($plans, 'field'), fieldPlans: $plans);
    $generator = new ResourceArtifacts;
    $manifest = $generator->plan($resource, 'entry_key', true, false);
    (new ArtifactWriter)->apply($manifest);

    foreach ($manifest->files as $path => $file) {
        if (str_starts_with($path, 'app/Actions/')) {
            expect(ArchitectureRules::violations($file['after'], 'Actions'))->toBe([]);
            require base_path($path);
        }
    }

    require app_path('Policies/ActionEntryPolicy.php');
    Gate::policy(ActionEntry::class, ActionEntryPolicy::class);
    $this->actingAs(new GenericUser(['id' => 1]));

    config()->set('database.default', 'unconfigured-default');
    $create = new CreateAction;
    $update = new UpdateAction;
    $delete = new DeleteAction;
    $restore = new RestoreAction;
    $purge = new ForceDeleteAction;
    $input = ['name' => 'Example', 'secret' => 'my-password', 'secret_confirmation' => 'my-password', 'amount' => '1200.35', 'settings' => '{"size":3}', 'notes' => '<p onclick="bad()">Safe <strong>bold</strong></p><script>bad()</script>', 'admin' => true];

    expect(fn () => $create->handle($input))->toThrow(AuthorizationException::class)
        ->and(ActionEntry::count())->toBe(0);

    $allowed = ['create', 'update', 'delete', 'restore', 'forceDelete'];
    Gate::before(static function ($user, string $ability) use (&$allowed): ?bool {
        return in_array($ability, $allowed, true) ? true : null;
    });

    expect(fn () => $create->handle([...$input, 'secret_confirmation' => 'wrong']))->toThrow(ValidationException::class);
    $record = $create->handle($input);
    $key = $record->getKey();
    $hash = $record->secret;

    expect(Hash::check('my-password', $hash))->toBeTrue()
        ->and($record->toArray())->not->toHaveKey('secret')
        ->and($record->fresh()->settings)->toBe(['size' => 3])
        ->and($record->fresh()->amount)->toBe('1200.35')
        ->and($record->fresh()->notes)->toBe('<p>Safe <strong>bold</strong></p>')
        ->and((bool) $record->fresh()->admin)->toBeFalse();

    $legacy = $record->fresh();
    $legacy->notes = '<p onmouseover="ignored()">Legacy</p>';
    $read = ReadValues::from($legacy, $plans);

    expect($read)->not->toHaveKey('secret')->and($read['notes'])->toBe('<p>Legacy</p>');
    expect(fn () => $create->handle($input))->toThrow(ValidationException::class);

    $allowed = [];

    expect(fn () => $update->handle($key, $input))->toThrow(AuthorizationException::class)
        ->and(fn () => $delete->handle($key))->toThrow(AuthorizationException::class);

    $allowed = ['create', 'update', 'delete', 'restore', 'forceDelete'];
    $scopes = ActionEntry::getAllGlobalScopes();

    try {
        ActionEntry::addGlobalScope('visible-entries', static fn (Builder $query) => $query->where('name', 'Outside this scope'));
        expect(fn () => $update->handle($key, $input))->toThrow(ModelNotFoundException::class);
    } finally {
        ActionEntry::setAllGlobalScopes($scopes);
    }

    $updated = $update->handle($key, [...$input, 'secret' => '']);
    expect($updated->secret)->toBe($hash)->and($updated->toArray())->not->toHaveKey('secret');
    expect(fn () => $update->handle($key, [...$input, 'settings' => '{bad']))->toThrow(ValidationException::class)
        ->and(fn () => $purge->handle($key))->toThrow(ModelNotFoundException::class);

    $changed = $update->handle($key, [...$input, 'settings' => '{}', 'secret' => 'replacement', 'secret_confirmation' => 'replacement']);
    expect(Hash::check('replacement', $changed->secret))->toBeTrue()
        ->and(json_decode($changed->fresh()->getRawOriginal('settings')))->toBeInstanceOf(stdClass::class);

    $hash = $changed->secret;
    $delete->handle($key);
    expect(ActionEntry::count())->toBe(0)->and(ActionEntry::onlyTrashed()->count())->toBe(1)
        ->and(fn () => $update->handle($key, $input))->toThrow(ModelNotFoundException::class);

    $allowed = [];
    expect(fn () => $restore->handle($key))->toThrow(AuthorizationException::class)
        ->and(fn () => $purge->handle($key))->toThrow(AuthorizationException::class);

    $allowed = ['restore', 'forceDelete', 'delete', 'create'];
    $restore->handle($key);

    expect(ActionEntry::findOrFail($key)->secret)->toBe($hash);
    $delete->handle($key);
    $purge->handle($key);

    expect(ActionEntry::withTrashed()->count())->toBe(0);
    ActionEntry::created(static function (): never {
        throw new RuntimeException('observer failed');
    });

    expect(fn () => $create->handle($input))->toThrow(RuntimeException::class, 'observer failed')
        ->and(ActionEntry::count())->toBe(0);

    $readOnly = new ResourceDefinition($definition, $identity, new OperationSelection(true, []));
    $files = $generator->plan($readOnly, 'entry_key', false, false)->files;
    expect(array_filter(array_keys($files), static fn (string $path): bool => str_starts_with($path, 'app/Actions/') || str_starts_with($path, 'app/Policies/')))->toBe([]);
});

it('hashes passwords with plain attributes and verifies custom setters before saving', function (): void {
    $column = FieldFixtures::column(['name' => 'secret']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Password);
    $input = ['secret' => 'private-value', 'secret_confirmation' => 'private-value'];
    $plain = new class extends Model {};

    $mutator = new class extends Model
    {
        public function setSecretAttribute(string $value): void
        {
            $this->attributes['secret'] = Hash::make($value);
        }
    };

    foreach ([$plain, $mutator] as $record) {
        ActionInput::assign($record, [$plan], $input);
        expect(Hash::check($input['secret'], $record->secret))->toBeTrue()
            ->and($record->toArray())->not->toHaveKey('secret');
    }

    $incompatible = new class extends Model
    {
        public function setSecretAttribute(string $value): void
        {
            $this->attributes['secret'] = $value;
        }
    };

    expect(fn () => ActionInput::assign($incompatible, [$plan], $input))->toThrow(RuntimeException::class);
});
