<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Sirius\LaravelBuilder\Schema\SchemaInspectionException;
use Sirius\LaravelBuilder\Schema\SchemaInspector;
use Tests\Fixtures\Discovery\Models\Admin\Category;

it('uses resolved model tables and the selected connection with prefixes and custom keys', function (): void {
    config(['database.connections.prefixed' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'tenant_']]);
    $connection = DB::connection('prefixed');
    $connection->getSchemaBuilder()->create('products', function (Blueprint $table): void {
        $table->string('code')->primary();
        $table->string('title')->nullable();
    });
    $model = (new Category)->setConnection('prefixed')->setTable('main.products')->setKeyName('code');
    $result = app(SchemaInspector::class)->inspect($model);
    expect($result->table)->toBe('main.products');
    expect($result->key)->toBe('code');
    expect(array_column($result->columns, 'name'))->toBe(['code', 'title']);
});

it('rejects mismatched tables before opening a connection', function (): void {
    $model = (new Category)->setConnection('nonexistent')->setTable('products');
    app(SchemaInspector::class)->inspect($model, 'other');
})->throws(SchemaInspectionException::class, 'differs from Model::getTable');

it('reports missing tables without writes', function (): void {
    app(SchemaInspector::class)->inspect((new Category)->setTable('absent'));
})->throws(SchemaInspectionException::class, 'missing or has no visible columns');

it('reports inaccessible connections without exposing connection secrets', function (): void {
    config(['database.connections.unavailable' => ['driver' => 'invalid-secret-driver', 'password' => 'never-display-this']]);
    expect(fn () => app(SchemaInspector::class)->inspect((new Category)->setConnection('unavailable')))
        ->toThrow(SchemaInspectionException::class, 'Schema inspection failed. Check the selected connection');
});

it('rejects composite primary keys', function (): void {
    DB::connection()->getSchemaBuilder()->create('categories', function (Blueprint $table): void {
        $table->integer('tenant_id');
        $table->integer('id');
        $table->primary(['tenant_id', 'id']);
    });
    app(SchemaInspector::class)->inspect(new Category);
})->throws(SchemaInspectionException::class, 'single-column primary key');

it('rejects a model whose key differs from the table key', function (): void {
    DB::connection()->getSchemaBuilder()->create('categories', function (Blueprint $table): void {
        $table->id('category_id');
    });
    app(SchemaInspector::class)->inspect(new Category);
})->throws(SchemaInspectionException::class, 'model key differs');

it('quotes suspicious table names without executing them', function (): void {
    DB::connection()->getSchemaBuilder()->create('categories', function (Blueprint $table): void {
        $table->id();
    });
    expect(fn () => app(SchemaInspector::class)->inspect((new Category)->setTable("categories'; drop table categories; --")))->toThrow(SchemaInspectionException::class);
    expect(DB::connection()->getSchemaBuilder()->hasTable('categories'))->toBeTrue();
});

it('honors both a declared table and overridden table resolution', function (): void {
    DB::connection()->getSchemaBuilder()->create('custom_products', function (Blueprint $table): void {
        $table->id();
    });
    $custom = new class extends Model
    {
        protected $table = 'custom_products';
    };
    $overridden = new class extends Model
    {
        public function getTable(): string
        {
            return 'custom_products';
        }
    };
    expect(app(SchemaInspector::class)->inspect($custom)->table)->toBe('custom_products');
    expect(app(SchemaInspector::class)->inspect($overridden)->table)->toBe('custom_products');
});

it('rejects invalid table references before connecting', function (string $table): void {
    app(SchemaInspector::class)->inspect((new Category)->setConnection('absent')->setTable($table));
})->with(['', ' ', '.products', 'main.', 'db.schema.table', "bad\0name"])->throws(SchemaInspectionException::class, 'table or schema.table');

it('explains unknown column types and does not guess SQLite enum options', function (): void {
    config(['database.connections.sqlite-specific' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::connection('sqlite-specific')->statement('create table categories (id integer primary key, value money, status text check (status in (\'a\', \'b\')))');
    $result = app(SchemaInspector::class)->inspect((new Category)->setConnection('sqlite-specific'));
    expect($result->columns[1]->type)->toBe('unknown');
    expect($result->columns[2]->enumValues)->toBeNull();
    expect(implode(' ', $result->warnings))->toContain('Unsupported or ambiguous type', 'CHECK constraints');
});

it('rejects tables without a primary key', function (): void {
    DB::connection()->getSchemaBuilder()->create('categories', function (Blueprint $table): void {
        $table->string('name');
    });
    app(SchemaInspector::class)->inspect(new Category);
})->throws(SchemaInspectionException::class, 'single-column primary key');
