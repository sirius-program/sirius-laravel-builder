<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Commands;

use Illuminate\Console\Command;
use Sirius\LaravelBuilder\Support\Preflight;

final class MakeCrudCommand extends Command
{
    protected $signature = 'make:crud {--check : Check prerequisites without generating files}';

    protected $description = 'Prepare an action-based Sirius UI CRUD resource (generation is not available yet)';

    public function handle(Preflight $preflight): int
    {
        $errors = $preflight->errors();
        foreach ($errors as $error) {
            $this->error($error);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }
        $this->info('CRUD prerequisites are ready. No files or database records were changed.');
        if ($this->option('check')) {
            return self::SUCCESS;
        }
        $this->warn('CRUD generation is not available in this milestone. Use --check to verify setup.');

        return self::FAILURE;
    }
}
