<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Generators;

use Diglactic\Breadcrumbs\Manager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Sirius\LaravelBuilder\Definitions\Layout;
use Sirius\LaravelBuilder\Definitions\Operation;
use Sirius\LaravelBuilder\Definitions\ResourceDefinition;

final readonly class ResourceArtifacts
{
    public function __construct(private StubRenderer $stubs = new StubRenderer, private FieldRenderer $fields = new FieldRenderer) {}

    public function plan(ResourceDefinition $resource, string $routeKey, bool $scaffold, bool $browser): ArtifactManifest
    {
        $manifest = new ArtifactManifest(base_path());
        $id = $resource->identity;
        $owner = $id->name;
        $path = str_replace('\\', '/', $owner);
        $model = '\\' . $resource->model->className;
        $pages = ['index' => ''];

        if (!preg_match('/^\w+$/D', $routeKey)) {
            throw new RuntimeException('Unsupported route key.');
        }

        if ($resource->layout === Layout::Pages) {
            if ($resource->operations->includes(Operation::Create)) {
                $pages['create'] = '/create';
            }

            $pages['show'] = '/{record:' . $routeKey . '}';
            if ($resource->operations->includes(Operation::Update)) {
                $pages['edit'] = $pages['show'] . '/edit';
            }
        }

        if (app()->routesAreCached()) {
            $manifest->blockers[] = 'Clear the route cache and run the wizard again so conflicts are checked against the current route files.';
        }

        $routes = $crumbs = [];
        $literal = var_export($owner, true);
        $routePath = str_replace('\\', '/', config('sirius-builder.paths.routes'));
        $source = $manifest->read($routePath) ?? throw new RuntimeException('Missing route file: ' . $routePath);
        $web = new ManagedBlock($source, $owner);
        $crumbPath = $resource->breadcrumbTarget;
        $crumbSource = null;

        if ($crumbPath !== null) {
            $crumbSource = $manifest->read($crumbPath) ?? throw new RuntimeException('Missing breadcrumb file.');
            $crumbBlock = new ManagedBlock($crumbSource, $owner);
        }

        $form = '';
        foreach ($resource->fieldPlans as $field) {
            $form .= $this->fields->render($field, Str::slug(str_replace('\\', '-', $owner)));
        }

        $this->file(
            $manifest,
            'app/Livewire/' . $path . '/ResourceTable.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $id->livewireNamespace . ";\n\nfinal class ResourceTable extends \\Sirius\\Ui\\Livewire\\Table\n{\n    protected function query(): \\Illuminate\\Support\\Collection { return collect(); }\n    protected function columns(): array { return []; }\n}\n"
        );

        foreach ($pages as $page => $suffix) {
            $class = ucfirst($page) . 'Page';
            $fqcn = $id->livewireNamespace . '\\' . $class;
            $name = $id->routes->namePrefix . $page;
            $url = $id->routes->urlPrefix . $suffix;
            $record = in_array($page, ['show', 'edit'], true);
            $mount = $record ? $model . ' $record' : '';
            $ability = ['index' => 'viewAny', 'create' => 'create', 'show' => 'view', 'edit' => 'update'][$page];
            $guard = $resource->policy->enforce ? '\\Illuminate\\Support\\Facades\\Gate::authorize(' . var_export($ability, true) . ', ' . ($record ? '$record' : $model . '::class') . ');' : '';
            $members = "public array \$form = [];\n\n    public function mount(" . $mount . "): void\n    {\n        " . $guard . "\n    }";

            $this->file(
                $manifest,
                'app/Livewire/' . $path . '/' . $class . '.php',
                $this->stubs->render('livewire', ['namespace' => $id->livewireNamespace, 'class' => $class, 'members' => $members, 'view' => var_export($id->viewName . '.' . $page, true)])
            );

            $content = '<h1>' . e(Str::headline(str_replace('\\', ' ', $owner)) . ' â€” ' . ucfirst($page)) . '</h1>';
            if (in_array($page, ['create', 'edit'], true)) {
                $content .= "\n" . $form;
            }

            $this->file(
                $manifest,
                $id->viewPath . '/' . $page . '.blade.php',
                $this->stubs->render('view', ['content' => $content])
            );

            $routes[] = '    \Illuminate\Support\Facades\Route::get(' . var_export($url, true) . ', \\' . $fqcn . '::class)->name(' . var_export($name, true) . ');';
            $manifest->routes[] = 'GET ' . $url . ' ' . $name;
            $pattern = preg_replace('/\{[^}]+\}/', '{}', $url);
            $sample = str_replace('{}', '42', $pattern);

            foreach (Route::getRoutes()->getRoutes() as $existing) {
                if (($existing->getAction()['sirius_builder'] ?? null) === $owner && str_contains($web->previous, '\\' . explode('@', $existing->getActionName())[0] . '::class')) {
                    continue;
                }

                $existingPath = '/' . ltrim($existing->uri(), '/');
                $matchesExisting = preg_match($existing->toSymfonyRoute()->compile()->getRegex(), $sample);
                $samePattern = preg_replace('/\{[^}]+\}/', '{}', $existingPath) === $pattern;
                $shadowsStatic = !str_contains($existingPath, '{') && preg_match('~^' . str_replace('\{\}', '[^/]+', preg_quote($pattern, '~')) . '$~D', $existingPath);

                if ($existing->getName() === $name || (in_array('GET', $existing->methods(), true) && ($matchesExisting || $samePattern || $shadowsStatic))) {
                    $manifest->blockers[] = 'Route conflict: ' . $existing->uri() . ' ' . $existing->getName();
                }
            }

            if (isset($crumbBlock)) {
                $quoted = var_export($name, true);
                $manager = app(Manager::class);

                if ($manager->exists($name) && !str_contains($crumbBlock->previous, '::for(' . $quoted . ',')) {
                    $manifest->blockers[] = 'Breadcrumb conflict: ' . $name;
                }

                $parent = $id->routes->namePrefix . 'index';
                $body = $page === 'index' ? '' : '$trail->parent(' . var_export($parent, true) . ");\n    ";
                $body .= '$trail->push(' . var_export($page === 'index' ? $owner : ucfirst($page), true) . ', route(' . $quoted . ($record ? ", ['record' => \$record]" : '') . '));';
                $crumbs[] = $this->stubs->render('breadcrumb', ['name' => $quoted, 'parameters' => $record ? ', ' . $model . ' $record' : '', 'body' => $body]);
                $manifest->routes[] = 'Trail ' . $name . ' parent: ' . ($page === 'index' ? 'root' : $parent);
            }
        }

        foreach ($resource->operations->selected as $op) {
            $class = Str::studly($op->value) . 'Action';
            $this->file(
                $manifest,
                'app/Actions/' . $path . '/' . $class . '.php',
                (new ActionRenderer($this->stubs))->render($resource, $op, $class)
            );
        }

        if ($scaffold) {
            $policy = str_replace('\\', '/', $resource->model->relativeName) . 'Policy';
            $ns = 'App\\Policies' . (dirname($policy) === '.' ? '' : '\\' . str_replace('/', '\\', dirname($policy)));
            $policyPath = 'app/Policies/' . $policy . '.php';

            if ($manifest->read($policyPath) !== null) {
                $manifest->blockers[] = 'Existing policy must be configured manually: ' . $policyPath;
            } else {
                $abilities = ['viewAny', 'view'];
                foreach ($resource->operations->selected as $operation) {
                    $abilities[] = $operation === Operation::PermanentDelete ? 'forceDelete' : $operation->value;
                }

                $methods = [];
                foreach ($abilities as $ability) {
                    $parameters = '\Illuminate\Contracts\Auth\Authenticatable $user' . (in_array($ability, ['viewAny', 'create'], true) ? '' : ', ' . $model . ' $record');
                    $methods[] = '    public function ' . $ability . '(' . $parameters . "): bool\n    {\n        return false;\n    }";
                }

                $this->file(
                    $manifest,
                    $policyPath,
                    $this->stubs->render('policy', [
                        'namespace' => $ns,
                        'class'     => basename($policy),
                        'methods'   => implode("\n\n", $methods),
                    ])
                );
            }
        }

        $index = var_export($id->routes->namePrefix . 'index', true);
        $this->file(
            $manifest,
            $id->testPath . '/ResourceTest.php',
            $this->stubs->render('pest', [
                'description' => var_export('registers ' . $owner . ' routes', true),
                'body'        => 'expect(\\Illuminate\\Support\\Facades\\Route::has(' . $index . '))->toBeTrue();',
            ])
        );

        if ($browser) {
            $this->file(
                $manifest,
                'tests/Browser/Crud/' . $path . '/ResourceTest.php',
                $this->stubs->render('pest', [
                    'description' => var_export('opens ' . $owner, true),
                    'body'        => 'visit(route(' . $index . '))->assertNoJavaScriptErrors();',
                ])
            );
        }

        $group = '\Illuminate\Support\Facades\Route::group([' . "'sirius_builder' => " . $literal . ", 'middleware' => " . var_export($id->routes->middleware, true) . "], static function (): void {\n" . implode("\n", $routes) . "\n});";
        $manifest->add($routePath, $web->replace($group), $source, true);

        if (isset($crumbBlock)) {
            $manifest->add($crumbPath, $crumbBlock->replace(implode("\n", $crumbs)), $crumbSource, true);
        }

        return $manifest;
    }

    private function file(ArtifactManifest $manifest, string $path, string $content): void
    {
        $manifest->add($path, $content, $manifest->read($path));
    }
}
