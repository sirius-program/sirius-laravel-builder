<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Sirius\LaravelBuilder\Definitions\IndexDefinition;
use Sirius\LaravelBuilder\Schema\SchemaInspector;
use Tests\Fixtures\Discovery\Models\Admin\Category;
use Tests\Support\DatabaseConnections;

it('inspects equivalent schemas on real engines without modifying rows or schema', function (string $driver): void {
    $connection = DB::connection($driver);
    try {
        $connection->getPdo();
    } catch (Throwable) {
        throw new RuntimeException('Cannot connect to the isolated ' . $driver . ' test database; check owner configuration.');
    }
    $schema = $connection->getSchemaBuilder();
    $table = 'builder_' . bin2hex(random_bytes(6));
    $parent = $table . '_parent';
    $created = [];
    try {
        $schema->create($parent, function (Blueprint $blueprint): void {
            $blueprint->id();
        });
        $created[] = $parent;
        $schema->create($table, function (Blueprint $blueprint) use ($parent): void {
            $blueprint->id();
            $blueprint->string('name', 80)->unique();
            $blueprint->decimal('price', 10, 2)->default(0);
            $blueprint->text('description')->nullable();
            $blueprint->foreignId('parent_id')->nullable()->constrained($parent);
            $blueprint->integer('quantity')->default(1);
            $blueprint->integer('double_quantity')->storedAs('quantity * 2');
            $blueprint->timestamp('published_at')->nullable();
        });
        $created[] = $table;
        $connection->table($table)->insert(['name' => 'Preserve me', 'price' => 12.50]);
        $beforeColumns = $schema->getColumns($table);
        $beforeRows = $connection->table($table)->get()->toArray();
        $beforeIndexes = $schema->getIndexes($table);
        $beforeForeignKeys = $schema->getForeignKeys($table);
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $model = (new Category)->setConnection($driver)->setTable($table);

        $result = app(SchemaInspector::class)->inspect($model);
        $queries = $connection->getQueryLog();
        $columns = array_column($result->columns, null, 'name');

        expect($result->driver)->toBe($driver === 'testing' ? 'sqlite' : $driver);
        expect($result->connection)->toBe($driver);
        expect($result->key)->toBe('id');
        expect($columns['name']->type)->toBe('string');
        expect($columns['description']->nullable)->toBeTrue();
        expect($columns['double_quantity']->generated)->toBeTrue();
        expect($columns['id']->autoIncrement)->toBeTrue();
        expect($result->foreignKeys[0]->foreignTable)->toBe($parent);
        expect(array_values(array_filter($result->indexes, fn (IndexDefinition $index): bool => $index->unique && !$index->primary)))->toHaveCount(1);
        if ($driver !== 'testing') {
            expect($columns['name']->length)->toBe(80);
            expect($columns['price']->precision)->toBe(10);
            expect($columns['price']->scale)->toBe(2);
        }
        foreach ($queries as $query) {
            expect(strtolower(ltrim($query['query'])))->toMatch('/^(select|pragma|with)\b/');
        }
        expect($schema->getColumns($table))->toBe($beforeColumns);
        expect($schema->getIndexes($table))->toBe($beforeIndexes);
        expect($schema->getForeignKeys($table))->toBe($beforeForeignKeys);
        expect($connection->table($table)->get()->toArray())->toEqual($beforeRows);
        $version = $connection->selectOne($driver === 'testing' ? 'select sqlite_version() as version' : 'select version() as version')->version;
        fwrite(STDOUT, "\nVerified {$driver}: {$version}\n");
    } finally {
        foreach (array_reverse($created) as $ownedTable) {
            $schema->drop($ownedTable);
        }
    }
})->with(DatabaseConnections::schemaDrivers());

if (array_intersect(DatabaseConnections::schemaDrivers(), ['mysql', 'mariadb']) !== []) {
    it('reads native enum values including punctuation', function (string $driver): void {
        $connection = DB::connection($driver);
        $table = 'builder_' . bin2hex(random_bytes(6));
        $schema = $connection->getSchemaBuilder();
        try {
            $connection->statement("create table {$table} (id bigint auto_increment primary key, status enum('draft', 'it''s ready', 'a,b', '') not null)");
            $result = app(SchemaInspector::class)->inspect((new Category)->setConnection($driver)->setTable($table));
            expect($result->columns[1]->enumValues)->toBe(['draft', "it's ready", 'a,b', '']);
        } finally {
            $schema->dropIfExists($table);
        }
    })->with(array_values(array_intersect(DatabaseConnections::schemaDrivers(), ['mysql', 'mariadb'])));

}

if (in_array('pgsql', DatabaseConnections::schemaDrivers(), true)) {
    it('resolves PostgreSQL enum types and identity columns in a custom schema', function (): void {
        $connection = DB::connection('pgsql');
        $namespace = 'builder_' . bin2hex(random_bytes(6));
        $connection->statement('create schema ' . $namespace);
        try {
            $connection->statement("create type {$namespace}.status as enum ('draft', 'it''s ready', 'a,b', '')");
            $connection->statement("create table {$namespace}.items (id bigint generated always as identity primary key, status {$namespace}.status not null)");
            $model = (new Category)->setConnection('pgsql')->setTable($namespace . '.items');
            $result = app(SchemaInspector::class)->inspect($model);
            expect($result->columns[0]->autoIncrement)->toBeTrue();
            expect($result->columns[0]->generated)->toBeTrue();
            expect($result->columns[1]->enumValues)->toBe(['draft', "it's ready", 'a,b', '']);
        } finally {
            $connection->statement('drop schema ' . $namespace . ' cascade');
        }
    });

}

if (array_diff(DatabaseConnections::schemaDrivers(), ['testing']) !== []) {
    it('handles connection prefixes without applying them twice', function (string $driver): void {
        $base = config('database.connections.' . $driver);
        $prefix = 'builder_' . bin2hex(random_bytes(6)) . '_';
        config(['database.connections.prefixed' => [...$base, 'prefix' => $prefix]]);
        $connection = DB::connection('prefixed');
        $schema = $connection->getSchemaBuilder();
        $schema->create('products', function (Blueprint $table): void {
            $table->string('code', 20)->primary();
            $table->string('title');
        });
        try {
            $model = (new Category)->setConnection('prefixed')->setTable('products')->setKeyName('code');
            $result = app(SchemaInspector::class)->inspect($model);
            expect($result->table)->toBe('products');
            expect($result->key)->toBe('code');
            expect(array_column($result->columns, 'name'))->toBe(['code', 'title']);
        } finally {
            $schema->drop('products');
            DB::purge('prefixed');
        }
    })->with(array_values(array_diff(DatabaseConnections::schemaDrivers(), ['testing'])));

}
