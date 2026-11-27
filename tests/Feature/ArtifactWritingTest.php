<?php

declare(strict_types=1);

use Diglactic\Breadcrumbs\Breadcrumbs;
use Diglactic\Breadcrumbs\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Sirius\LaravelBuilder\Generators\ArtifactManifest;
use Sirius\LaravelBuilder\Generators\ArtifactWriter;
use Sirius\LaravelBuilder\Generators\ManagedBlock;
use Symfony\Component\Console\Tester\CommandTester;

it('loads generated pages and trails and regenerates without duplicate registrations', function (string $layout): void {
    $name = prepareWizardModel();
    $target = base_path('routes/custom.php');
    file_put_contents($target, "<?php\n// Keep this comment.\n");
    config()->set('breadcrumbs.files', $target);
    app()->register(ServiceProvider::class);
    $answers = [$name, $name, '', '', 'no', $layout, 'create,update', '/entries', 'entries.', 'no', 'no', 'no', 'Generate'];

    $run = static function (array $options = []) use ($answers): CommandTester {
        $tester = new CommandTester(Artisan::all()['make:crud']);
        $tester->setInputs($answers);
        expect($tester->execute($options))->toBe(0, $tester->getDisplay());

        return $tester;
    };

    $before = file_get_contents(base_path('routes/web.php'));
    $run(['--dry-run' => true]);

    expect(file_get_contents(base_path('routes/web.php')))->toBe($before)
        ->and(is_dir(app_path('Livewire/' . $name)))->toBeFalse();

    $run();
    foreach (glob(app_path('Livewire/' . $name . '/*.php')) as $file) {
        require_once $file;
    }

    require base_path('routes/web.php');
    require $target;

    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('entries.index');
    config()->set('livewire.component_layout', 'fixture-layout');
    $this->get('/entries')->assertOk()->assertSee('Index');

    expect($route)->not->toBeNull()
        ->and(Livewire::test(explode('@', $route->getActionName())[0])->html())->toContain('Index')
        ->and(Breadcrumbs::generate('entries.index')->last()->url)->toBe(route('entries.index'));

    if ($layout === 'pages') {
        $class = 'App\\Models\\' . $name;
        $record = new $class;
        $record->setAttribute('id', 7);
        expect(Route::getRoutes()->match(Request::create('/entries/create'))->getName())->toBe('entries.create')
            ->and(Breadcrumbs::generate('entries.edit', $record)->pluck('url')->all())->toBe([route('entries.index'), route('entries.edit', $record)]);
    } else {
        expect(Route::has('entries.show'))->toBeFalse()->and(Breadcrumbs::exists('entries.show'))->toBeFalse();
    }

    $web = file_get_contents(base_path('routes/web.php'));
    $crumb = file_get_contents($target);
    $run();

    expect(file_get_contents(base_path('routes/web.php')))->toBe($web)
        ->and(file_get_contents($target))->toBe($crumb)->toContain('// Keep this comment.');
})->with(['pages', 'dialogs']);

it('protects reviewed contents and requires precise overwrite approval', function (): void {
    $path = 'routes/web.php';
    $m = new ArtifactManifest(base_path());
    $before = $m->read($path);
    $after = "<?php\n// replacement\n";
    $m->add($path, $after, $before);
    $writer = new ArtifactWriter;

    expect(fn () => $writer->apply($m))->toThrow(RuntimeException::class, 'approve')
        ->and(fn () => $writer->apply($m, [$path, 'extra.php']))->toThrow(RuntimeException::class, 'approve');

    file_put_contents(base_path($path), $before . '// simultaneous edit');

    expect(fn () => $writer->apply($m, [$path]))->toThrow(RuntimeException::class, 'changed since review');
    expect(file_get_contents(base_path($path)))->toBe($before . '// simultaneous edit');
});

it('rolls back installed files and directories when a later replacement fails', function (): void {
    $m = new ArtifactManifest(base_path());
    $before = $m->read('routes/web.php');
    $m->add('app/Temporary/First.php', '<?php // new', null);
    $m->add('routes/web.php', '<?php // changed', $before, true);
    $m->add('routes/last.php', '<?php // last', null);

    $writer = new class extends ArtifactWriter
    {
        protected function install(string $temp, string $target): void
        {
            if (basename($target) === 'last.php') {
                throw new RuntimeException('simulated failure');
            }
            parent::install($temp, $target);
        }
    };

    expect(fn () => $writer->apply($m))->toThrow(RuntimeException::class, 'simulated failure')
        ->and(is_dir(app_path('Temporary')))->toBeFalse()
        ->and(file_get_contents(base_path('routes/web.php')))->toBe($before);
});

it('rejects unsafe paths and invalid route documents', function (): void {
    $manifest = new ArtifactManifest(base_path());
    foreach (['../outside.php', 'C:\outside.php', 'vendor/change.php', 'routes/CON.php', 'routes/name.php:stream.php'] as $bad) {
        expect(fn (): ?string => $manifest->read($bad))->toThrow(RuntimeException::class);
    }

    foreach (['<?php if (', '<?php return;', '<?php namespace Demo;', '<?php ?>text'] as $source) {
        expect(fn (): ManagedBlock => new ManagedBlock($source, 'Demo'))->toThrow(RuntimeException::class);
    }

    $source = (new ManagedBlock("<?php\n", 'Demo'))->replace('// hello');
    expect(fn (): ManagedBlock => new ManagedBlock(str_replace('// hello', '// edit', $source), 'Demo'))->toThrow(RuntimeException::class, 'edited');
});

it('blocks conflicting registrations before writing any file', function (string $conflict): void {
    $name = prepareWizardModel();
    $breadcrumb = base_path('routes/breadcrumbs.php');
    file_put_contents($breadcrumb, "<?php\n// Existing definitions\n");
    app()->register(ServiceProvider::class);

    match ($conflict) {
        'breadcrumb'    => Breadcrumbs::for('entries.create', static function (): void {}),
        'route name'    => Route::get('/different', static fn (): string => '')->name('entries.index'),
        'dynamic route' => Route::get('/{all}', static fn (): string => '')->where('all', '.*'),
        'static route'  => Route::get('/entries/archived', static fn (): string => ''),
    };

    $before = file_get_contents(base_path('routes/web.php'));
    $tester = new CommandTester(Artisan::all()['make:crud']);
    $tester->setInputs([$name, $name, '', '', 'no', 'pages', 'create', '/entries', 'entries.', 'no', 'no', 'no', 'Generate']);

    expect($tester->execute([]))->toBe(1)
        ->and($tester->getDisplay())->toContain('conflict:')
        ->and(file_get_contents(base_path('routes/web.php')))->toBe($before)
        ->and(is_dir(app_path('Livewire/' . $name)))->toBeFalse();
})->with(['breadcrumb', 'route name', 'dynamic route', 'static route']);

it('preserves a customized action unless its exact overwrite is approved', function (bool $allow): void {
    $name = prepareWizardModel();
    $action = app_path('Actions/' . $name . '/CreateAction.php');
    mkdir(dirname($action));
    file_put_contents($action, '<?php // custom');
    $tester = new CommandTester(Artisan::all()['make:crud']);
    $tester->setInputs([$name, $name, '', '', 'no', 'pages', 'create', '', '', 'no', 'no', 'no', 'Generate', $allow ? 'yes' : 'no']);

    expect($tester->execute([]))->toBe(0)
        ->and(file_get_contents($action) !== '<?php // custom')->toBe($allow)
        ->and(is_dir(app_path('Livewire/' . $name)))->toBe($allow);
})->with([false, true]);
