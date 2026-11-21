<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\ModelDefinition;
use Sirius\LaravelBuilder\Definitions\ResourceIdentity;
use Sirius\LaravelBuilder\Definitions\RouteDefinition;

final class ResourceNamer
{
    public function identify(ModelDefinition $model, string $tableName, ?string $resourceName = null, string $applicationNamespace = 'App'): ResourceIdentity
    {
        if (trim($tableName) === '') {
            throw new InvalidArgumentException('The resolved model table must not be empty.');
        }

        $name = $resourceName ?? $model->relativeName;
        $this->validateName($name);
        $this->validateName($applicationNamespace);
        $segments = explode('\\', $name);
        $basename = array_pop($segments);
        $directories = array_map(static fn (string $segment): string => Str::kebab(Str::studly($segment)), $segments);
        $viewSegments = [...$directories, Str::kebab(Str::studly($basename))];
        $routeSegments = [...$directories, Str::kebab(Str::pluralStudly(Str::studly($basename)))];

        return new ResourceIdentity(
            name: $name,
            suggestedTable: $tableName,
            livewireNamespace: $applicationNamespace . '\\Livewire\\' . $name,
            actionNamespace: $applicationNamespace . '\\Actions\\' . $name,
            viewPath: 'resources/views/livewire/' . implode('/', $viewSegments),
            viewName: 'livewire.' . implode('.', $viewSegments),
            testPath: 'tests/Feature/Crud/' . str_replace('\\', '/', $name),
            routes: new RouteDefinition('/' . implode('/', $routeSegments), implode('.', $routeSegments) . '.'),
        );
    }

    private function validateName(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $name)) {
            throw new InvalidArgumentException('Use PHP identifiers separated by backslashes; paths, whitespace, and empty segments are not allowed.');
        }
        foreach (explode('\\', $name) as $segment) {
            if (Str::studly($segment) === '' || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/iD', $segment)) {
                throw new InvalidArgumentException('Resource identifier cannot form a portable path: ' . $segment);
            }
            // PHP also reserves scalar type names that token parsing alone does not reject.
            if (in_array(strtolower($segment), ['int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never', 'self', 'parent'], true)) {
                throw new InvalidArgumentException('Reserved PHP identifier: ' . $segment);
            }
            try {
                $tokens = token_get_all('<?php class ' . $segment . ' {}', TOKEN_PARSE);
                if (!is_array($tokens[3]) || $tokens[3][0] !== T_STRING) {
                    throw new InvalidArgumentException('Invalid PHP identifier: ' . $segment);
                }
            } catch (\ParseError) {
                throw new InvalidArgumentException('Reserved or invalid PHP identifier: ' . $segment);
            }
        }
    }
}
