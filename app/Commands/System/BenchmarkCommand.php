<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Commands\Concerns\SelectsTargetDisk;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class BenchmarkCommand extends Command
{
    use InteractsWithLocale;
    use SelectsTargetDisk;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'toolbox:benchmark
                            {--disk= : Target drive letter or directory path for sequential disk I/O test}
                            {--lang= : Interface language (en or pt_BR)}';

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

        $diskOption = $this->option('disk');
        $explicitDisk = is_string($diskOption) && trim($diskOption) !== '' ? trim($diskOption) : null;

        $targetDisk = $this->selectTargetDisk(
            driver: $driver,
            explicitTarget: $explicitDisk,
            includeHomeOption: false,
            includeCustomPathOption: true,
        );

        $effectiveTarget = $targetDisk ?? sys_get_temp_dir();
        note((string) __('messages.toolbox.benchmark_target', [
            'disk' => $effectiveTarget,
        ]));

        $result = spin(
            callback: fn () => $driver->runSystemBenchmark(64, 1.0, $targetDisk),
            message: (string) __('messages.spinners.toolbox_benchmark'),
        );

        if (isset($result->details['fallback_from']) && is_string($result->details['fallback_from'])) {
            warning((string) __('messages.toolbox.benchmark_readonly_fallback', [
                'requested' => $result->details['fallback_from'],
                'fallback' => (string) ($result->details['target_disk'] ?? sys_get_temp_dir()),
            ]));
        }

        return $this->renderActionResult($result);
    }
}
