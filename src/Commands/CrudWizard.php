<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Commands;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\FieldDefinition;
use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\Layout;
use Sirius\LaravelBuilder\Definitions\ModelDefinition;
use Sirius\LaravelBuilder\Definitions\Operation;
use Sirius\LaravelBuilder\Definitions\OperationSelection;
use Sirius\LaravelBuilder\Definitions\PolicyDefinition;
use Sirius\LaravelBuilder\Definitions\ResourceDefinition;
use Sirius\LaravelBuilder\Definitions\ResourceIdentity;
use Sirius\LaravelBuilder\Definitions\RouteDefinition;
use Sirius\LaravelBuilder\Discovery\BreadcrumbTargets;
use Sirius\LaravelBuilder\Discovery\ModelDiscovery;
use Sirius\LaravelBuilder\Support\ResourceNamer;
use Sirius\LaravelBuilder\Support\SelectedModel;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class CrudWizard
{
    public function run(OutputStyle $output): int
    {
        $fieldQuestions = new WizardFields(new Factory($output));
        $models = app(ModelDiscovery::class)->discover(base_path(config('sirius-builder.paths.models')));
        if (!$models) {
            throw new InvalidArgumentException('No Eloquent models found in the configured models directory.');
        }

        do {
            $names = array_column($models, 'relativeName');
            $name = search('Select a model', fn (?string $query): array => array_values(array_filter($names, fn (string $name): bool => stripos($name, $query ?? '') !== false)));
            $definition = $models[array_search($name, $names, true)];
            $model = new SelectedModel($definition);

            do {
                $namer = new ResourceNamer;

                $resource = text(
                    'Resource name',
                    default: $definition->relativeName,
                    required: true,
                    validate: static function (string $name) use ($definition, $model, $namer): ?string {
                        try {
                            $namer->identify($definition, $model->table(), $name);

                            return null;
                        } catch (InvalidArgumentException $e) {
                            return $e->getMessage();
                        }
                    });

                $table = $model->inspect(
                    text(
                        'Database table',
                        default: $model->table(),
                        required: true,
                        validate: static fn (string $value): ?string => $value === $model->table() ? null : 'Use the table resolved by the selected model.'
                    )
                );

                $identity = $namer->identify($definition, $table->table, $resource);
                $fields = $fieldQuestions->collect($table, $model, [], false);
                [$operations, $layout, $routes, $policy, $scaffold, $browser, $breadcrumb] = $this->settings($definition, $identity, $model);

                do {
                    $review = new ResourceDefinition(
                        model: $definition,
                        identity: new ResourceIdentity(
                            $resource,
                            $table->table,
                            $identity->livewireNamespace,
                            $identity->actionNamespace,
                            $identity->viewPath,
                            $identity->viewName,
                            $identity->testPath,
                            $routes
                        ),
                        operations: $operations,
                        layout: $layout,
                        policy: $policy,
                        fields: array_values(array_map(static fn (FieldPlan $plan): FieldDefinition => $plan->field, $fields)),
                        fieldPlans: array_values($fields),
                        breadcrumbTarget: str_ends_with($breadcrumb, '.php') ? $breadcrumb : null
                    );

                    (new WizardReview)->display($output, $review, $table, $model, $scaffold, $browser, $breadcrumb);
                    $next = select('Next step', ['Edit a section', 'Restart', 'Generate', 'Cancel'], default: 'Cancel');

                    if ($next === 'Cancel') {
                        return 0;
                    }

                    if ($next === 'Generate') {
                        throw new InvalidArgumentException($policy->enforce && !$policy->className && !$scaffold ? 'Generation blocked by missing policy.' : 'Resource reviewed. File generation will be available in a later phase.');
                    }

                    if ($next === 'Restart') {
                        break 2;
                    }

                    $section = select('Section to edit', ['Resource', 'Fields', 'Operations and routes']);
                    if ($section === 'Resource') {
                        break;
                    }

                    if ($section === 'Fields') {
                        $fields = $fieldQuestions->collect($table, $model, $fields, true);
                    } else {
                        [$operations,$layout,$routes,$policy,$scaffold,$browser,$breadcrumb] = $this->settings($definition, $identity, $model);
                    }
                } while (true);
            } while (true);
        } while (true);
    }

    /** @return array{OperationSelection,Layout,RouteDefinition,PolicyDefinition,bool,bool,string} */
    private function settings(ModelDefinition $definition, ResourceIdentity $identity, SelectedModel $model): array
    {
        $available = array_column(OperationSelection::available($definition->softDeletes), 'value');
        $layout = Layout::from(select('Resource layout', array_column(Layout::cases(), 'value')));
        $operations = multiselect('Write operations', $available, default: $available);
        $url = text('Resource URL', default: '/' . trim(config('sirius-builder.routes.prefix') . $identity->routes->urlPrefix, '/'), required: true, validate: fn (string $s): ?string => preg_match('~^/(?:[\w-]+/)*[\w-]+$~D', $s) ? null : 'Use an absolute static path.');
        $prefix = text('Route name prefix', default: config('sirius-builder.routes.name_prefix') . $identity->routes->namePrefix, required: true, validate: fn (string $s): ?string => preg_match('/^[\w-]+(?:\.[\w-]+)*\.?$/D', $s) ? null : 'Use dot-separated route names.');
        $middleware = config('sirius-builder.routes.middleware', ['auth']);
        $middleware = confirm('Require authentication?', true) ? array_unique([...$middleware, 'auth']) : array_filter($middleware, fn (string $item): bool => $item !== 'auth' && !str_starts_with($item, 'auth:'));
        $enforce = confirm('Enforce model policy?', true);
        $policy = $model->policy();
        $scaffold = $enforce && !$policy && confirm('Plan a deny-by-default policy?', true);
        $browser = confirm('Include browser tests?', false);

        return [
            new OperationSelection($definition->softDeletes, array_map(Operation::from(...), $operations)),
            $layout,
            new RouteDefinition($url, rtrim($prefix, '.') . '.', array_values($middleware)),
            new PolicyDefinition($enforce, $policy),
            $scaffold,
            $browser,
            $this->breadcrumb(),
        ];
    }

    private function breadcrumb(): string
    {
        if (!app()->bound('Diglactic\Breadcrumbs\Manager')) {
            return 'package unavailable';
        }

        $default = base_path('routes/breadcrumbs.php');
        $files = config('breadcrumbs.files', $default);
        if ($files === $default && !file_exists($default)) {
            return 'default file missing';
        }

        $targets = (new BreadcrumbTargets)->discover(base_path(), $files);

        return count($targets) > 1 ? select('Breadcrumb target', $targets) : ($targets[0] ?? 'disabled');
    }
}
