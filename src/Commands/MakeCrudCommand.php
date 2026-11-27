<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Commands;

use Illuminate\Console\Command;
use Sirius\LaravelBuilder\Support\Preflight;

final class MakeCrudCommand extends Command
{
    protected $signature = 'make:crud {--check : Check prerequisites without generating files} {--dry-run : Preview generation without writing files}';

    protected $description = 'Generate an action-based Sirius UI resource scaffold';

    public function handle(Preflight $preflight): int
    {
        $errors = $preflight->errors();
        foreach ($errors as $error) {
            $this->components->error($error);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }

        $this->components->info('CRUD prerequisites are ready. No files or database records were changed.');
        if ($this->option('check')) {
            return self::SUCCESS;
        }

        if (!$this->input->isInteractive()) {
            $this->components->error('An interactive terminal is required for the CRUD wizard.');

            return self::FAILURE;
        }

        try {
            return app(CrudWizard::class)->run($this->output, (bool) $this->option('dry-run'));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->components->error($exception->getMessage());
        }

        return self::FAILURE;
    }
}
