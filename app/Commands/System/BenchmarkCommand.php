<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

class BenchmarkCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'toolbox:benchmark {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Run a fast CPU single-core and Disk sequential I/O micro-benchmark (64 MB sample)';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.toolbox.benchmark_section'));

        $result = spin(
            callback: fn () => $driver->runSystemBenchmark(64, 1.0),
            message: (string) __('messages.spinners.toolbox_benchmark'),
        );

        return $this->renderActionResult($result);
    }
}
