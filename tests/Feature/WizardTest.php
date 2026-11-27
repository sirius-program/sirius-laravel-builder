<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Sirius\LaravelBuilder\Commands\CrudWizard;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

it('reviews the resource through the console and leaves the application untouched', function (string $transition): void {
    $name = prepareWizardModel();
    $before = file_get_contents(base_path('routes/web.php'));
    $tester = new CommandTester(Artisan::all()['make:crud']);
    $answers = [$name, $name, '', '', 'no', 'pages', 'create,update', '', '', 'yes', 'no', 'no'];
    $inputs = $answers;

    if ($transition === 'Restart') {
        $inputs = [...$inputs, 'Restart', ...$answers];
    }

    if ($transition === 'Edit') {
        $inputs = [...$inputs, 'Edit a section', 'Operations and routes', 'dialogs', 'delete', '', '', 'no', 'no', 'no'];
    }

    $tester->setInputs([...$inputs, $transition === 'Generate' ? 'Generate' : 'Cancel']);
    expect($tester->execute([]))->toBe($transition === 'Generate' ? 1 : 0);
    $display = $tester->getDisplay();
    expect($display)->toContain('Review resource', 'wizard_entries', '.show', 'CreateAction.php')->not->toContain("\033[");

    if ($transition === 'Edit') {
        $last = substr($display, strrpos($display, 'Review resource'));
        expect($last)->toContain('Layout: dialogs', 'DeleteAction.php')->not->toContain('.show', 'CreateAction.php');
    }

    expect(file_get_contents(base_path('routes/web.php')))->toBe($before)
        ->and(is_dir(app_path('Livewire/' . $name)))->toBeFalse()
        ->and(DB::table('wizard_entries')->count())->toBe(0);
})->with(['Cancel', 'Restart', 'Edit', 'Generate']);

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

it('accepts a read-only preview using rich keyboard prompts', function (): void {
    prepareWizardModel();
    Prompt::flushState();
    $enter = Key::ENTER;
    Prompt::fake([Key::DOWN, ...array_fill(0, 13, $enter)]);
    $output = new BufferedOutput;
    try {
        expect((new CrudWizard)->run(new OutputStyle(new ArrayInput([]), $output)))->toBe(0)
            ->and($output->fetch())->toContain('Review resource', 'wizard_entries');
    } finally {
        Prompt::flushState();
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'Native Windows exercises the Symfony fallback.');

it('rejects empty model discovery before prompting', function (): void {
    mkdir(app_path('EmptyModels'));
    config()->set('sirius-builder.paths.models', 'app/EmptyModels');
    $this->artisan('make:crud')->expectsOutputToContain('No Eloquent models')->assertFailed();
});

it('selects an existing breadcrumb file without executing its contents', function (string $mode): void {
    $name = prepareWizardModel();
    app()->bind('Diglactic\Breadcrumbs\Manager', static fn (): stdClass => new stdClass);
    $one = base_path('routes/breadcrumbs.php');
    $two = base_path('routes/second.php');
    if ($mode !== 'missing') {
        file_put_contents($one, '<?php throw new Exception;');
    }
    if ($mode === 'multiple' || $mode === 'custom') {
        file_put_contents($two, '<?php throw new Exception;');
    }
    config()->set('breadcrumbs.files', match ($mode) {
        'disabled' => false,'multiple' => [$one, $two],'custom' => $two,default => $one
    });
    $tester = new CommandTester(Artisan::all()['make:crud']);
    $answers = [$name, $name, '', '', 'no', 'pages', 'create', '', '', 'yes', 'no', 'no'];
    if ($mode === 'multiple') {
        $answers[] = 'routes/second.php';
    }
    $tester->setInputs([...$answers, 'Cancel']);
    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Breadcrumbs: ' . match ($mode) {
            'missing' => 'default file missing','disabled' => 'disabled','custom','multiple' => 'routes/second.php',default => 'routes/breadcrumbs.php'
        });
})->with(['missing', 'disabled', 'default', 'custom', 'multiple']);

it('keeps a custom password confirmation label when editing a field', function (): void {
    $name = prepareWizardModel();
    Schema::table('wizard_entries', static function (Blueprint $table): void {
        $table->string('secret');
    });
    $tester = new CommandTester(Artisan::all()['make:crud']);
    $tester->setInputs([
        $name, $name, '', '', 'no', 'yes', 'Passphrase', 'password', 'Repeat passphrase', 'bad||rules', '', '', '', '', '',
        'pages', 'create', '', '', 'yes', 'no', 'no', 'Edit a section', 'Fields', 'no', 'yes', 'New label', '', '', '', '', '', 'Cancel',
    ]);
    $status = $tester->execute([]);
    expect($status)->toBe(0, $tester->getDisplay());
    $last = substr($tester->getDisplay(), strrpos($tester->getDisplay(), 'Review resource'));
    expect($last)->toContain('New label', 'Confirmation: Repeat passphrase', 'confirmed');
});

it('requests only the endpoint for a plain select and accepts an empty source', function (): void {
    $name = prepareWizardModel();
    Schema::table('wizard_entries', static function (Blueprint $table): void {
        $table->integer('owner_id');
    });
    $tester = new CommandTester(Artisan::all()['make:crud']);
    $tester->setInputs([$name, $name, '', '', 'no', 'yes', '', 'select', '', '', '', '', 'dialogs', 'delete', '', '', 'yes', 'no', 'no', 'Cancel']);
    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('Options: configure manually')->not->toContain('Password confirmation label');
});
