<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Tests\Support\ArchitectureRules;

it('rejects deliberate architectural violations', function (string $layer, string $body, bool $package): void {
    expect(ArchitectureRules::violations('<?php ' . $body, $layer, $package))->not->toBeEmpty();
})->with([
    'definition database dependency'     => ['Definitions', 'use Illuminate\Support\Facades\DB; DB::select("select 1");', true],
    'inference filesystem dependency'    => ['Inference', 'file_get_contents("schema.json");', true],
    'pure service locator'               => ['Definitions', 'app("db");', true],
    'package consumer coupling'          => ['Generators', 'new \App\Models\Category;', true],
    'command bypasses schema layer'      => ['Commands', '\Illuminate\Support\Facades\DB::select("select 1");', true],
    'command writes files directly'      => ['Commands', 'file_put_contents("web.php", "");', true],
    'component writes an instance'       => ['Livewire', '$category->save();', false],
    'component writes through a builder' => ['Livewire', '\App\Models\Category::query()->update(["name" => "bad"]);', false],
    'component writes statically'        => ['Livewire', '\App\Models\Category::create([]);', false],
    'component nullsafe write'           => ['Livewire', '$category?->delete();', false],
    'component dynamic write'            => ['Livewire', '$category->{$method}();', false],
    'schema mutates records'             => ['Schema', '$connection->statement("delete from categories");', true],
    'action calls Livewire'              => ['Actions', 'new \Livewire\Component;', false],
    'action renders views'               => ['Actions', 'view("category");', false],
    'action adds a second public method' => ['Actions', 'class Bad { public function execute(): void {} }', false],
    'missing return type'                => ['Definitions', 'class Bad { public function name() {} }', true],
    'missing parameter type'             => ['Definitions', 'class Bad { public function name($value): string { return $value; } }', true],
    'debugging termination'              => ['Generators', 'die("stop");', true],
]);

it('permits reads and action delegation in components', function (): void {
    expect(ArchitectureRules::violations('<?php $category = \App\Models\Category::query()->find(1); $action->handle(["name" => "Tools"]);', 'Livewire'))->toBe([]);
});

it('permits persistence inside actions', function (): void {
    expect(ArchitectureRules::violations('<?php $category->save();', 'Actions'))->toBe([]);
});

it('detects database queries inside Blade directives', function (): void {
    $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());

    $source = $compiler->compileString('@php \Illuminate\Support\Facades\DB::table("categories")->get(); @endphp');

    expect(ArchitectureRules::violations($source, 'Views'))->not->toBeEmpty();
});
