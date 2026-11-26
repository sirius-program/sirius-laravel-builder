<?php

declare(strict_types=1);

use Sirius\LaravelBuilder\Definitions\EnumDefinition;
use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Definitions\IndexDefinition;
use Sirius\LaravelBuilder\Definitions\TableDefinition;
use Sirius\LaravelBuilder\Definitions\ValidationConstraint;
use Sirius\LaravelBuilder\Definitions\ValidationDefinition;
use Sirius\LaravelBuilder\Inference\EnumOptions;
use Sirius\LaravelBuilder\Inference\FieldInference;
use Sirius\LaravelBuilder\Inference\FieldPlanner;
use Sirius\LaravelBuilder\Inference\RuleParser;
use Tests\Fixtures\Discovery\Enums\Status;
use Tests\Support\FieldFixtures;

it('offers deterministic field candidates with special type and id precedence', function (string $type, string $name, array $types, bool $included): void {
    $suggestion = (new FieldInference)->suggest(FieldFixtures::column(['name' => $name, 'type' => $type]));
    expect($suggestion->types)->toBe($types);
    expect($suggestion->included)->toBe($included);
})->with([
    ['integer', 'parent_id', [FieldType::Select, FieldType::Number, FieldType::Text], true],
    ['enum', 'status_id', [FieldType::Select], true],
    ['date', 'date_id', [FieldType::Date], true],
    ['uuid', 'user_id', [FieldType::Select, FieldType::Text], true],
    ['string', 'id', [FieldType::Text, FieldType::Email, FieldType::Password, FieldType::Url, FieldType::Phone], false],
    ['timestamp', 'published', [FieldType::Datetime], false],
    ['string', 'updated_at', [FieldType::Text, FieldType::Email, FieldType::Password, FieldType::Url, FieldType::Phone], false],
    ['text', 'body', [FieldType::Textarea, FieldType::Richtext], true],
    ['boolean', 'enabled', [FieldType::Boolean], true],
    ['decimal', 'price', [FieldType::Number, FieldType::Currency], true],
    ['json', 'data', [FieldType::Textarea], true],
    ['binary', 'data', [], true],
]);

it('labels fields and identifies omitted required values without inventing model defaults', function (): void {
    $required = FieldFixtures::column(['name' => 'display_name']);
    $optional = FieldFixtures::column(['name' => 'optional', 'nullable' => true]);
    $generated = FieldFixtures::column(['name' => 'generated', 'autoIncrement' => true]);
    expect((new FieldInference)->suggest($required)->label)->toBe('Display Name');
    expect((new FieldPlanner)->omittedRequired(FieldFixtures::table([$required, $optional, $generated]), []))->toBe(['display_name']);
    expect((new FieldPlanner)->omittedRequired(FieldFixtures::table([$required]), [], ['display_name']))->toBe([]);
});

it('retains structured constraints when editing shared and operation-specific rules', function (): void {
    $constraint = new ValidationConstraint('in', ['a,b', 'value|with|pipes']);
    $rules = new ValidationDefinition(['string'], ['required'], ['sometimes'], [$constraint]);
    $edited = (new RuleParser)->edit($rules, 'string|max:20', null, 'nullable');
    expect($edited->rules(false))->toBe(['string', 'max:20', 'required']);
    expect($edited->rules(true))->toBe(['string', 'max:20', 'nullable']);
    expect($edited->constraints)->toBe([$constraint]);
});

it('rejects unsafe or malformed pipe input', function (string $input): void {
    (new RuleParser)->parse($input);
})->with([
    'required||string',
    'string|',
    'regex:/a|b/',
    'not_regex:/a/',
    'unique:users,email,client_id',
    'exists:users,id',
    'in:a,b',
    'max:',
    "string\n",
    'required|nullable',
    'Rule::unique()',
])->throws(InvalidArgumentException::class);

it('maps enum cases to record options and rejects incompatible values', function (): void {
    $column = FieldFixtures::column(['type' => 'enum', 'enumValues' => ['draft', 'published']]);
    $options = (new EnumOptions)->map([['name' => 'Draft', 'value' => 'draft'], ['name' => 'Published', 'value' => 'published']], $column);
    expect($options)->toBe([['value' => 'draft', 'label' => 'Draft'], ['value' => 'published', 'label' => 'Published']]);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Select, enum: new EnumDefinition(Status::class, 'Status', 'string'), options: $options);
    expect($plan->validation->constraints[0]->values)->toBe(['draft', 'published']);
    expect(fn (): array => (new EnumOptions)->map([['name' => 'Other', 'value' => 'other']], $column))->toThrow(InvalidArgumentException::class);
});

it('rejects empty and duplicate enum option ids', function (array $cases): void {
    (new EnumOptions)->map($cases, FieldFixtures::column());
})->with([
    [[]],
    [[['name' => 'Blank', 'value' => '']]],
    [[['name' => 'First', 'value' => 0], ['name' => 'Second', 'value' => '0']]],
])->throws(InvalidArgumentException::class);

it('normalizes blank endpoints and rejects cross-origin sources', function (): void {
    $column = FieldFixtures::column(['name' => 'parent_id']);
    $planner = new FieldPlanner;
    expect($planner->plan(FieldFixtures::table([$column]), $column, FieldType::Select, endpoint: ' ')->field->endpoint)->toBeNull();
    expect($planner->plan(FieldFixtures::table([$column]), $column, FieldType::Select, endpoint: '/options')->field->endpoint)->toBe('/options');
    expect(fn (): FieldPlan => $planner->plan(FieldFixtures::table([$column]), $column, FieldType::Select, endpoint: '//example.com'))->toThrow(InvalidArgumentException::class);
});

it('preserves database defaults without making null valid for a nonnullable column', function (): void {
    $column = FieldFixtures::column(['default' => 'value']);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Text);
    expect($plan->validation->create)->toBe(['sometimes', 'required']);
    expect($plan->validation->update)->toBe(['sometimes', 'required']);
});

it('rejects unsupported mappings and mismatched decimal casts', function (): void {
    $column = FieldFixtures::column(['type' => 'binary']);
    expect(fn (): FieldPlan => (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Text))->toThrow(InvalidArgumentException::class);
    $column = FieldFixtures::column(['type' => 'decimal', 'precision' => 8, 'scale' => 2]);
    expect(fn (): FieldPlan => (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Currency, cast: 'decimal:3'))->toThrow(InvalidArgumentException::class, 'cast scale');
});

it('requires manual handling for composite unique constraints and conflicting password confirmation columns', function (): void {
    $column = FieldFixtures::column(['name' => 'password']);
    $confirmation = FieldFixtures::column(['name' => 'password_confirmation']);
    expect(fn (): FieldPlan => (new FieldPlanner)->plan(FieldFixtures::table([$column, $confirmation]), $column, FieldType::Password))->toThrow(InvalidArgumentException::class, 'conflicts');
    $table = new TableDefinition('testing', 'mysql', 'categories', 'id', [$column], [new IndexDefinition('tenant_password', ['tenant_id', 'password'], true, false, 'btree', true)], [], []);
    $plan = (new FieldPlanner)->plan($table, $column, FieldType::Text);
    expect($plan->validation->constraints)->toBe([]);
    expect(implode(' ', $plan->warnings))->toContain('composite');
});

it('stores edited field plans without losing structured rules', function (): void {
    $column = FieldFixtures::column();
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Text);
    $edited = $plan->withValidation((new RuleParser)->edit($plan->validation, 'string|max:30'));
    expect($edited->field->rules)->toBe(['string', 'max:30', 'required']);
    expect($edited->column)->toBe($column);
    expect($plan->field->rules)->toBe(['string', 'max:80', 'required']);
});

it('supports enum casts on string columns and rejects a different model cast', function (): void {
    $column = FieldFixtures::column(['name' => 'status']);
    $enum = new EnumDefinition(Status::class, 'Status', 'string');
    $options = [['value' => 'draft', 'label' => 'Draft']];
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Select, enum: $enum, options: $options, cast: Status::class);
    expect($plan->options)->toBe($options);
    expect(fn (): FieldPlan => (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Select, enum: $enum, options: $options, cast: 'OtherEnum'))->toThrow(InvalidArgumentException::class, 'model cast');
});

it('uses the selected password label as the confirmation default and preserves custom labels', function (): void {
    $column = FieldFixtures::column(['name' => 'password']);
    $table = FieldFixtures::table([$column]);
    $planner = new FieldPlanner;
    expect($planner->plan($table, $column, FieldType::Password, label: 'Access Password')->confirmation->label)->toBe('Confirm Access Password');
    $custom = $planner->plan($table, $column, FieldType::Password, label: 'Access Password', confirmationLabel: 'Repeat your password');
    expect($custom->confirmation->label)->toBe('Repeat your password');
    expect($custom->confirmation->column)->toBe('password_confirmation');
    expect($custom->confirmation->writable)->toBeFalse();
    expect($custom->withValidation($custom->validation)->confirmation->label)->toBe('Repeat your password');
});

it('rejects empty or unrelated confirmation labels', function (FieldType $type, string $label): void {
    $column = FieldFixtures::column(['name' => 'password']);
    (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, $type, confirmationLabel: $label);
})->with([[FieldType::Password, ''], [FieldType::Password, '  '], [FieldType::Text, 'Repeat']])->throws(InvalidArgumentException::class, 'confirmation label');

it('supports explicit ULID metadata without guessing from arbitrary string length', function (): void {
    $column = FieldFixtures::column(['name' => 'record_id', 'type' => 'ulid', 'length' => 26]);
    expect((new FieldInference)->suggest($column)->types)->toBe([FieldType::Select, FieldType::Text]);
    $plan = (new FieldPlanner)->plan(FieldFixtures::table([$column]), $column, FieldType::Text);
    expect($plan->validation->shared)->toContain('ulid');
    $string = FieldFixtures::column(['length' => 26]);
    expect((new FieldPlanner)->plan(FieldFixtures::table([$string]), $string, FieldType::Text)->validation->shared)->not->toContain('ulid');
});
