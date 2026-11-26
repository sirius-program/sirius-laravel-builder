<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Sirius\LaravelBuilder\Definitions\EnumDefinition;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Discovery\EnumCaseDiscovery;
use Sirius\LaravelBuilder\Inference\FieldPlanner;
use Sirius\LaravelBuilder\Inference\RuleParser;
use Sirius\LaravelBuilder\Schema\SchemaInspector;
use Sirius\LaravelBuilder\Support\FieldValidation;
use Tests\Fixtures\Discovery\Enums\Mode;
use Tests\Fixtures\Discovery\Enums\Status;
use Tests\Fixtures\Discovery\Models\Admin\Category;
use Tests\Support\FieldFixtures;

it('validates inferred unique rules with trusted record identity on the selected database', function (): void {
    DB::connection()->getSchemaBuilder()->create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('email', 80)->unique();
    });
    DB::table('categories')->insert([['email' => 'first@example.com'], ['email' => 'second@example.com']]);
    $schema = app(SchemaInspector::class)->inspect(new Category);
    $plan = (new FieldPlanner)->plan($schema, $schema->columns[1], FieldType::Email);
    $adapter = new FieldValidation;
    $record = Category::query()->where('email', 'first@example.com')->firstOrFail();
    $rules = $adapter->rules($plan, 'Asia/Jakarta', true, $record);

    expect(Validator::make(['email' => 'first@example.com'], ['email' => $rules])->passes())->toBeTrue();
    expect(Validator::make(['email' => 'second@example.com'], ['email' => $rules])->fails())->toBeTrue();
    expect(Validator::make(['email' => 'first@example.com'], ['email' => $adapter->rules($plan, 'Asia/Jakarta')])->fails())->toBeTrue();
    expect(fn (): array => $adapter->rules($plan, 'Asia/Jakarta', true))->toThrow(InvalidArgumentException::class, 'trusted');
    $edited = (new RuleParser)->edit($plan->validation, 'string|email|max:40');
    expect(Validator::make(['email' => 'second@example.com'], ['email' => $adapter->rules($plan, 'Asia/Jakarta', true, $record, $edited)])->fails())->toBeTrue();
    expect(DB::table('categories')->count())->toBe(2);
});

it('validates foreign keys using the correct connection prefix and referenced table', function (): void {
    $schema = DB::connection()->getSchemaBuilder();
    $schema->create('parents', function (Blueprint $table): void {
        $table->id();
    });
    $schema->create('categories', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->constrained('parents');
    });
    try {
        $id = DB::table('parents')->insertGetId([]);
        $metadata = app(SchemaInspector::class)->inspect(new Category);
        $plan = (new FieldPlanner)->plan($metadata, $metadata->columns[1], FieldType::Select);
        $rules = (new FieldValidation)->rules($plan, 'UTC');
        expect(Validator::make(['parent_id' => (string) $id], ['parent_id' => $rules])->passes())->toBeTrue();
        expect(Validator::make(['parent_id' => '99999'], ['parent_id' => $rules])->fails())->toBeTrue();
    } finally {
        $schema->drop('categories');
        $schema->drop('parents');
    }
});

it('requires password confirmation and preserves blank update passwords by omitting input', function (): void {
    $column = FieldFixtures::column(['name' => 'password']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Password);
    $adapter = new FieldValidation;
    $rules = ['password' => $adapter->rules($plan, 'UTC')];
    expect(Validator::make(['password' => 'secret'], $rules)->fails())->toBeTrue();
    expect(Validator::make(['password' => 'secret', 'password_confirmation' => 'secret'], $rules)->passes())->toBeTrue();
    $input = $adapter->input([$plan], ['password' => '', 'password_confirmation' => ''], true);
    expect($input)->toBe([]);
    expect(Validator::make($input, ['password' => $adapter->rules($plan, 'UTC', true)])->passes())->toBeTrue();
    expect($plan->confirmation->label)->toBe('Confirm Password');
    expect($plan->confirmation->writable)->toBeFalse();
});

it('checks exact decimal bounds and nullable values through Laravel validation', function (): void {
    $column = FieldFixtures::column(['type' => 'decimal', 'nativeType' => 'decimal(5,2)', 'precision' => 5, 'scale' => 2, 'length' => null, 'nullable' => true]);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Currency);
    $rules = ['name' => (new FieldValidation)->rules($plan, 'UTC')];
    expect(Validator::make(['name' => '999.99'], $rules)->passes())->toBeTrue();
    expect(Validator::make(['name' => null], $rules)->passes())->toBeTrue();
    expect(Validator::make(['name' => '1000.00'], $rules)->fails())->toBeTrue();
    expect(Validator::make(['name' => '1.001'], $rules)->fails())->toBeTrue();
    expect(Validator::make(['name' => 0.1], $rules)->fails())->toBeTrue();
});

it('keeps generated columns prohibited even when selected', function (): void {
    $column = FieldFixtures::column(['generated' => true]);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Text);
    expect(Validator::make(['name' => 'changed'], ['name' => (new FieldValidation)->rules($plan, 'UTC')])->fails())->toBeTrue();
    expect($plan->field->writable)->toBeFalse();
});

it('does not infer unconditional uniqueness from SQLite partial or expression indexes', function (): void {
    config(['database.connections.evidence' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $db = DB::connection('evidence');
    $db->statement('create table categories (id integer primary key, email varchar(80), status integer)');
    $db->statement('create unique index active_email on categories (email) where status = 1');
    $db->statement('create unique index lower_email on categories (lower(email))');
    $metadata = app(SchemaInspector::class)->inspect((new Category)->setConnection('evidence'));
    $indexes = array_column($metadata->indexes, null, 'name');
    expect($indexes['active_email']->unconditional)->toBeFalse();
    expect($indexes['lower_email']->unconditional)->toBeFalse();
    $plan = (new FieldPlanner)->plan($metadata, $metadata->columns[1], FieldType::Email);
    expect($plan->validation->constraints)->toBe([]);
    expect(implode(' ', $plan->warnings))->toContain('explicit');
});

it('reads backed and pure enum cases without coupling inference to reflection', function (): void {
    $discovery = new EnumCaseDiscovery;
    $pure = $discovery->discover(new EnumDefinition(Mode::class, 'Mode', null));
    expect($pure[0]['value'])->toBe($pure[0]['name']);
    $backed = $discovery->discover(new EnumDefinition(Status::class, 'Status', 'string'));
    expect($backed[0]['value'])->toBe(Status::cases()[0]->value);
});

it('validates integer boolean controls and numeric text without changing string length semantics', function (): void {
    $column = FieldFixtures::column(['name' => 'enabled', 'type' => 'integer', 'nativeType' => 'tinyint']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Boolean);
    expect(Validator::make(['enabled' => true], ['enabled' => (new FieldValidation)->rules($plan, 'UTC')])->passes())->toBeTrue();
    $column = FieldFixtures::column(['name' => 'external_id', 'length' => 3]);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Number);
    $rules = ['external_id' => (new FieldValidation)->rules($plan, 'UTC')];
    expect(Validator::make(['external_id' => '999'], $rules)->passes())->toBeTrue();
    expect(Validator::make(['external_id' => '1000'], $rules)->fails())->toBeTrue();
    expect(Validator::make(['external_id' => 'abc'], $rules)->fails())->toBeTrue();
});
