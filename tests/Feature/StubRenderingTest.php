<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Sirius\LaravelBuilder\Definitions\ColumnDefinition;
use Sirius\LaravelBuilder\Definitions\EnumDefinition;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Definitions\TableDefinition;
use Sirius\LaravelBuilder\Generators\FieldRenderer;
use Sirius\LaravelBuilder\Generators\StubRenderer;
use Sirius\LaravelBuilder\Inference\FieldPlanner;
use Sirius\Ui\SiriusUiServiceProvider;
use Tests\Fixtures\Discovery\Enums\Status;

it('publishes independent overridable templates and keeps customized files on republish', function (): void {
    $renderer = new StubRenderer;
    $this->artisan('vendor:publish', ['--tag' => 'sirius-builder-stubs'])->assertSuccessful();
    $target = base_path('stubs/sirius-builder/view.stub');
    file_put_contents($target, '<main>%{content}%</main>');
    unlink(base_path('stubs/sirius-builder/breadcrumb.stub'));
    $this->artisan('vendor:publish', ['--tag' => 'sirius-builder-stubs'])->assertSuccessful();
    expect($renderer->render('view', ['content' => '%{content}%']))->toBe('<main>%{content}%</main>')
        ->and(is_file(base_path('stubs/sirius-builder/breadcrumb.stub')))->toBeTrue();
    unlink($target);
    expect($renderer->render('view', ['content' => 'Hello']))->toContain('<div>', 'Hello');
});

it('rejects broken overrides instead of generating incomplete output', function (string $override): void {
    $this->artisan('vendor:publish', ['--tag' => 'sirius-builder-stubs'])->assertSuccessful();
    file_put_contents(base_path('stubs/sirius-builder/view.stub'), $override);
    expect(fn (): string => (new StubRenderer)->render('view', ['content' => 'example']))->toThrow(InvalidArgumentException::class);
})->with(['<div/>', '%{content}%%{wrong}%', '%{content}%%{broken']);

it('renders each supported Sirius control from schema metadata', function (FieldType $type, string $columnType, string $component): void {
    $column = new ColumnDefinition('sample', $columnType, $columnType, false, null, null, null, null, false, false, false, null, null);
    $table = new TableDefinition('testing', 'sqlite', 'examples', 'id', [$column], [], [], []);
    $plan = (new FieldPlanner)->plan($table, $column, $type, 'Custom title', confirmationLabel: $type === FieldType::Password ? 'Repeat secret' : null);
    $blade = (new FieldRenderer)->render($plan, 'example');
    expect($blade)->toContain('<x-sirius::' . $component, 'wire:model="form.sample"', 'error-key="form.sample"', 'id="example-sample"');
    if ($type === FieldType::Password) {
        expect($blade)->toContain('Repeat secret', 'form.sample_confirmation')->not->toContain('value=');
    }
    if ($type === FieldType::Select) {
        expect($blade)->not->toContain('search-url');
    }
})->with([
    [FieldType::Text, 'string', 'input'], [FieldType::Email, 'string', 'input'], [FieldType::Url, 'string', 'input'], [FieldType::Password, 'string', 'input'],
    [FieldType::Number, 'integer', 'input'], [FieldType::Boolean, 'boolean', 'switch'], [FieldType::Select, 'enum', 'select'],
    [FieldType::Phone, 'string', 'phone'], [FieldType::Currency, 'decimal', 'currency'], [FieldType::Textarea, 'text', 'textarea'],
    [FieldType::Richtext, 'text', 'richtext'], [FieldType::Date, 'date', 'datetime-picker'], [FieldType::Time, 'time', 'datetime-picker'], [FieldType::Datetime, 'datetime', 'datetime-picker'],
]);

it('compiles user labels as literal text in the installed Sirius component', function (): void {
    file_put_contents(base_path('composer.json'), json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
    $this->app->register(SiriusUiServiceProvider::class);
    $column = new ColumnDefinition('sample', 'string', 'varchar', false, null, 100, null, null, false, false, false, null, null);
    $plan = (new FieldPlanner)->plan(new TableDefinition('testing', 'sqlite', 'examples', 'id', [$column], [], [], []), $column, FieldType::Text, '{{ 7 * 6 }} " <strong>');
    $html = Blade::render((new FieldRenderer)->render($plan, 'sample'));
    expect($html)->toContain('{{ 7 * 6 }}', '&lt;strong&gt;', 'wire:model="form.sample"');
});

it('renders parseable PHP and rejects malformed customized PHP', function (): void {
    $renderer = new StubRenderer;
    $context = ['namespace' => 'App\Actions', 'class' => 'CreateExample', 'parameters' => 'string $name', 'return_type' => 'string', 'body' => 'return $name;'];
    expect($renderer->render('action', $context))->toContain('function handle(string $name): string', 'return $name;')
        ->and($renderer->render('livewire', ['namespace' => 'App\Livewire', 'class' => 'IndexPage', 'members' => '', 'view' => "'example'"]))->toContain("return view('example')")
        ->and($renderer->render('pest', ['description' => "'displays a resource'", 'body' => 'expect(true)->toBeTrue();']))->toContain("it('displays a resource'")
        ->and($renderer->render('breadcrumb', ['name' => "'examples.index'", 'parameters' => '', 'body' => "\$trail->push('Examples');"]))->toContain("Breadcrumbs::for('examples.index'");
    $this->artisan('vendor:publish', ['--tag' => 'sirius-builder-stubs'])->assertSuccessful();
    file_put_contents(base_path('stubs/sirius-builder/action.stub'), "\ninvalid syntax;", FILE_APPEND);
    expect(fn (): string => $renderer->render('action', $context))->toThrow(InvalidArgumentException::class, 'Invalid PHP');
    unset($context['parameters']);
    expect(fn (): string => $renderer->render('action', $context))->toThrow(InvalidArgumentException::class, 'Incorrect render context');
});

it('preserves enum option IDs and the configured Sirius namespace', function (): void {
    config()->set('sirius-ui.namespace.blade', 'custom-ui');
    $column = new ColumnDefinition('state', 'enum', 'enum', false, null, null, null, null, false, false, false, null, ['01', '02']);
    $table = new TableDefinition('test', 'mysql', 'items', 'id', [$column], [], [], []);
    $enum = new EnumDefinition(Status::class, 'Status', 'string');
    $plan = (new FieldPlanner)->plan($table, $column, FieldType::Select, enum: $enum, options: [['value' => '01', 'label' => 'First'], ['value' => '02', 'label' => 'Second']]);
    expect((new FieldRenderer)->render($plan, 'items'))->toContain('<x-custom-ui::select', "'value' => '01'", "'label' => 'Second'")->not->toContain('search-url');
});
