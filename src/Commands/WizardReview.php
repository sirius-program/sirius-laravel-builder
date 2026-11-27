<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Commands;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Sirius\LaravelBuilder\Definitions\Layout;
use Sirius\LaravelBuilder\Definitions\Operation;
use Sirius\LaravelBuilder\Definitions\ResourceDefinition;
use Sirius\LaravelBuilder\Definitions\TableDefinition;
use Sirius\LaravelBuilder\Inference\FieldPlanner;
use Sirius\LaravelBuilder\Support\SelectedModel;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

use function Termwind\parse;

final class WizardReview
{
    public function display(OutputInterface $output, ResourceDefinition $resource, TableDefinition $table, SelectedModel $model, bool $scaffold, bool $browser, string $breadcrumb): void
    {
        $identity = $resource->identity;
        $lines = [
            $identity->name . ' (' . $resource->model->className . ')',
            'Connection / table: ' . $table->connection . ' / ' . $table->table,
            'Layout: ' . $resource->layout->value,
            'Operations: ' . implode(', ', ['index', 'read', ...array_column($resource->operations->selected, 'value')]),
            'Middleware: ' . implode(', ', $identity->routes->middleware),
            'Policy: ' . ($resource->policy->enforce ? ($resource->policy->className ?? ($scaffold ? 'scaffold deny-by-default policy' : 'missing (blocked)')) : 'disabled'),
            'Breadcrumbs: ' . $breadcrumb,
            ...$table->warnings,
        ];

        foreach ($resource->fieldPlans as $plan) {
            $lines[] = $plan->field->column . ' — ' . $plan->field->label . ' (' . $plan->field->type->value . ')';
            $lines[] = 'Create rules: ' . implode('|', $plan->validation->rules(false));
            $lines[] = 'Update rules: ' . implode('|', $plan->validation->rules(true));

            foreach ($plan->validation->constraints as $constraint) {
                $lines[] = $constraint->kind . ' ' . implode(', ', $constraint->values) . ' ' . $constraint->table . ' ' . $constraint->column;
            }

            if ($plan->confirmation) {
                $lines[] = 'Confirmation: ' . $plan->confirmation->label;
            }

            if ($plan->field->type->value === 'select') {
                $lines[] = 'Options: ' . ($plan->field->enum->className ?? $plan->field->endpoint ?? 'configure manually');
                foreach ($plan->options as $option) {
                    $lines[] = $option['value'] . ' = ' . $option['label'];
                }
            }

            array_push($lines, ...$plan->warnings);
        }

        foreach ((new FieldPlanner)->omittedRequired($table, array_column($resource->fields, 'column')) as $column) {
            $lines[] = 'Supply omitted required column: ' . $column;
        }

        $pages = ['index' => ''];
        if ($resource->layout === Layout::Pages) {
            $record = '{record:' . $model->routeKey() . '}';
            if ($resource->operations->includes(Operation::Create)) {
                $pages['create'] = '/create';
            }
            $pages['show'] = '/' . $record;
            if ($resource->operations->includes(Operation::Update)) {
                $pages['edit'] = '/' . $record . '/edit';
            }
        }

        $path = str_replace('\\', '/', $identity->name);
        $files = ['app/Livewire/' . $path . '/ResourceTable.php', $identity->testPath . '/ResourceTest.php'];
        foreach ($pages as $page => $suffix) {
            $uri = $identity->routes->urlPrefix . $suffix;
            $name = $identity->routes->namePrefix . $page;
            $lines[] = 'GET ' . $uri . ' ' . $name;

            foreach (Route::getRoutes()->getRoutes() as $route) {
                if ($route->getName() === $name || (in_array('GET', $route->methods(), true) && preg_replace('/\{[^}]+\}/', '{}', $route->uri()) === preg_replace('/\{[^}]+\}/', '{}', trim($uri, '/')))) {
                    $lines[] = 'Route conflict: ' . $route->uri() . ' ' . $route->getName();
                }
            }

            if ($resource->breadcrumbTarget !== null) {
                $lines[] = 'Trail ' . $name . ' parent: ' . ($page === 'index' ? 'root' : $identity->routes->namePrefix . 'index');
            }

            $files[] = 'app/Livewire/' . $path . '/' . ucfirst($page) . 'Page.php';
            $files[] = $identity->viewPath . '/' . $page . '.blade.php';
        }

        foreach ($resource->operations->selected as $operation) {
            $files[] = 'app/Actions/' . $path . '/' . Str::studly($operation->value) . 'Action.php';
        }

        if ($browser) {
            $files[] = 'tests/Browser/Crud/' . $path . '/ResourceTest.php';
        }

        if ($scaffold) {
            $files[] = 'app/Policies/' . str_replace('\\', '/', $resource->model->relativeName) . 'Policy.php';
        }

        foreach ($files as $file) {
            $lines[] = $file . (file_exists(base_path($file)) ? ' (file conflict)' : '');
        }

        $lines[] = 'Update routes: ' . config('sirius-builder.paths.routes');
        $output->writeln(parse('<b>Review resource</b>'));
        foreach ($lines as $line) {
            $output->writeln(OutputFormatter::escape($line));
        }
    }
}
