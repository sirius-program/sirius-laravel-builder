<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Static guardrails, complemented by action and Livewire behavior tests. */
final class ArchitectureRules
{
    private const array WRITES = ['save', 'savequietly', 'create', 'createquietly', 'update', 'updatequietly', 'delete', 'deletequietly', 'destroy', 'forcedelete', 'restore', 'insert', 'insertgetid', 'upsert', 'updateorcreate', 'firstorcreate', 'increment', 'decrement', 'truncate', 'push', 'touch', 'attach', 'detach', 'sync', 'statement', 'unprepared'];

    private const array IO = ['file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'unlink', 'mkdir', 'rmdir', 'rename', 'copy', 'glob', 'scandir', 'readfile', 'app', 'resolve', 'db', 'view', 'response'];

    /** @return list<string> */
    public static function violations(string $source, string $layer, bool $package = false): array
    {
        $nodes = (new ParserFactory)->createForHostVersion()->parse($source) ?? [];
        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);
        $finder = new NodeFinder;
        $errors = [];
        $pure = in_array($layer, ['Definitions', 'Inference'], true);

        foreach ($finder->findInstanceOf($nodes, Node\Name::class) as $name) {
            $value = $name->toString();
            if ($layer === 'Discovery' && in_array($value, [Model::class, SoftDeletes::class], true)) {
                continue;
            }
            $prefixes = $package ? ['App\\'] : [];
            if ($pure) {
                $prefixes = [...$prefixes, 'Illuminate\\', 'Livewire\\', 'Sirius\\Ui\\', 'Symfony\\', 'Sirius\\LaravelBuilder\\Commands\\', 'Sirius\\LaravelBuilder\\Generators\\', 'Sirius\\LaravelBuilder\\Discovery\\', 'Sirius\\LaravelBuilder\\Schema\\', 'Sirius\\LaravelBuilder\\Support\\', 'PDO', 'SplFileObject', 'DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator'];
            }
            if ($layer === 'Actions') {
                $prefixes = [...$prefixes, 'Livewire\\', 'Illuminate\\View\\', 'Illuminate\\Contracts\\View\\'];
            }
            if (in_array($layer, ['Commands', 'Discovery', 'Generators'], true)) {
                $prefixes = [...$prefixes, 'Illuminate\\Database\\', DB::class, Schema::class, 'PDO'];
            }
            if ($layer === 'Views') {
                $prefixes = [...$prefixes, 'App\\Models\\', 'Illuminate\\Database\\', DB::class, Schema::class, 'DB', 'Schema'];
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    $errors[] = "{$layer}: forbidden dependency {$value}";
                }
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
            if (!$call->name instanceof Node\Name) {
                $errors[] = "{$layer}: dynamic function calls require explicit review";

                continue;
            }
            $function = strtolower($call->name->toString());
            $forbidden = ['dd', 'dump', 'var_dump'];
            if ($pure || $layer === 'Views') {
                $forbidden = [...$forbidden, ...self::IO];
            }
            if ($layer === 'Commands') {
                $forbidden = [...$forbidden, 'file_put_contents', 'fwrite', 'unlink', 'mkdir', 'rename', 'copy'];
            }
            if ($layer === 'Actions') {
                $forbidden[] = 'view';
            }
            if (in_array($function, $forbidden, true)) {
                $errors[] = "{$layer}: forbidden function {$function}";
            }
        }

        foreach ($finder->find($nodes, fn (Node $node): bool => $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall) as $call) {
            if (!$call instanceof Node\Expr\MethodCall && !$call instanceof Node\Expr\NullsafeMethodCall && !$call instanceof Node\Expr\StaticCall) {
                continue;
            }
            if (in_array($layer, ['Livewire', 'Schema', 'Views', 'Discovery'], true)) {
                if (!$call->name instanceof Node\Identifier) {
                    $errors[] = "{$layer}: dynamic method calls require explicit review";
                } elseif (in_array(strtolower($call->name->toString()), [...self::WRITES, ...($layer === 'Discovery' ? ['query', 'newquery', 'getconnection', 'resolveconnection'] : [])], true)) {
                    $errors[] = "{$layer}: persistence call {$call->name->toString()}";
                }
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassMethod::class) as $method) {
            $name = $method->name->toString();
            if ($layer === 'Actions' && $method->isPublic() && !in_array($name, ['__construct', 'handle'], true)) {
                $errors[] = "Actions: unexpected public method {$name}";
            }
            if (!in_array($name, ['__construct', '__destruct'], true) && $method->returnType === null) {
                $errors[] = "{$layer}: missing return type on {$name}";
            }
            foreach ($method->params as $parameter) {
                if ($parameter->type === null) {
                    $errors[] = "{$layer}: missing parameter type on {$name}";
                }
            }
        }

        if ($finder->findInstanceOf($nodes, Node\Expr\Exit_::class) !== []) {
            $errors[] = "{$layer}: debugging termination";
        }

        return array_values(array_unique($errors));
    }
}
