<?php

declare(strict_types=1);
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\DatabaseTestCase;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

uses(DatabaseTestCase::class)->in('Database');

function prepareWizardModel(): string
{
    $name = 'Entry' . bin2hex(random_bytes(4));
    $directory = app_path('WizardModels');
    mkdir($directory);
    $source = $directory . '/' . $name . '.php';
    file_put_contents($source, '<?php namespace App\Models; final class ' . $name . ' extends \Illuminate\Database\Eloquent\Model { protected $table = "wizard_entries"; }');
    require $source;
    config()->set('sirius-builder.paths.models', 'app/WizardModels');
    Schema::create('wizard_entries', static function (Blueprint $table): void {
        $table->id();
    });

    return $name;
}
