<?php

declare(strict_types=1);

use App\Commands\Maintenance\LargeFilesCommand;
use App\Commands\System\BenchmarkCommand;
use App\Contracts\SystemDriverInterface;
use App\DTOs\ActionResult;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Create a Mockery-backed SystemDriverInterface mock with configurable storage mounts and callbacks.
 *
 * @param  array<int, array{filesystem: string, total: string, used: string, available: string, usage_percent: string, mount: string}>  $volumes
 */
function makeDiskSelectorMockDriver(
    array $volumes,
    ?callable $onFindLargeFiles = null,
    ?callable $onBenchmark = null,
): SystemDriverInterface {
    $mock = Mockery::mock(SystemDriverInterface::class)->shouldIgnoreMissing();

    $mock->shouldReceive('getStorageHealth')->andReturn($volumes);

    $mock->shouldReceive('findLargeFiles')->andReturnUsing(
        fn (?string $directory = null, int $limit = 15): array => $onFindLargeFiles !== null
            ? $onFindLargeFiles($directory, $limit)
            : []
    );

    $mock->shouldReceive('runSystemBenchmark')->andReturnUsing(
        function (int $diskSizeMb = 64, float $cpuDurationSeconds = 1.0, ?string $targetDirectory = null) use ($onBenchmark): ActionResult {
            if ($onBenchmark !== null) {
                return $onBenchmark($diskSizeMb, $cpuDurationSeconds, $targetDirectory);
            }

            return new ActionResult(
                success: true,
                message: 'messages.toolbox.benchmark_success',
                details: [
                    'target_disk' => $targetDirectory ?? sys_get_temp_dir(),
                    'cpu_score' => '500,000 SHA-256 ops/sec',
                    'disk_write_speed' => '420.0 MB/s (64 MB sample)',
                    'disk_read_speed' => '1,100.0 MB/s (64 MB sample)',
                    'storage_tier' => '🚀 NVMe / Fast SSD',
                    'driver' => 'Mock',
                ],
            );
        }
    );

    return $mock;
}

it('exposes --disk option on toolbox:benchmark and disk selector translations in en and pt_BR', function (): void {
    /** @var Kernel $kernel */
    $kernel = $this->app->make(Kernel::class);
    $definition = $kernel->all()['toolbox:benchmark']->getDefinition();

    expect($definition->hasOption('disk'))->toBeTrue()
        ->and($definition->hasOption('lang'))->toBeTrue();

    foreach (['en', 'pt_BR'] as $locale) {
        app()->setLocale($locale);
        app('translator')->setLocale($locale);

        expect((string) __('messages.disk_selector.volume_option', ['mount' => 'D:\\', 'available' => '412.0 GB', 'total' => '931.5 GB']))
            ->toContain('D:\\')
            ->toContain('412.0 GB')
            ->toContain('931.5 GB')
            ->and((string) __('messages.toolbox.benchmark_readonly_fallback', ['requested' => 'E:\\', 'fallback' => '/tmp']))
            ->toContain('E:\\')
            ->toContain('/tmp');
    }
});

it('skips interactive selector when maintenance:large-files receives an explicit path and prompts mounted disks when omitted', function (): void {
    $tempDir1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_sel_vol1_' . uniqid();
    $tempDir2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_sel_vol2_' . uniqid();
    mkdir($tempDir1, 0777, true);
    mkdir($tempDir2, 0777, true);
    $sampleFile = $tempDir2 . DIRECTORY_SEPARATOR . 'backup.tar';
    file_put_contents($sampleFile, str_repeat('X', 2048));

    /** @var array<int, string|null> $recordedTargets */
    $recordedTargets = [];

    $driver = makeDiskSelectorMockDriver(
        volumes: [
            ['filesystem' => 'C: (NTFS)', 'total' => '237.5 GB', 'used' => '189.3 GB', 'available' => '48.2 GB', 'usage_percent' => '79.7%', 'mount' => $tempDir1],
            ['filesystem' => 'D: (NTFS)', 'total' => '931.5 GB', 'used' => '519.5 GB', 'available' => '412.0 GB', 'usage_percent' => '55.8%', 'mount' => $tempDir2],
        ],
        onFindLargeFiles: function (?string $dir) use (&$recordedTargets, $tempDir2, $sampleFile): array {
            $recordedTargets[] = $dir;

            return $dir === $tempDir2
                ? [['path' => $sampleFile, 'size_bytes' => 2048.0, 'size_formatted' => '2.00 KB', 'modified_at' => '2026-11-18 12:00']]
                : [];
        },
    );

    $command = new class extends LargeFilesCommand
    {
        public bool $promptShown = false;

        /** @var array<string, string> */
        public array $capturedOptions = [];

        public string $choiceToReturn = '2';

        protected function promptNumericMenu(string $promptLabel, array $options, string $defaultKey = '1'): string
        {
            $this->promptShown = true;
            $this->capturedOptions = $options;

            return $this->choiceToReturn;
        }
    };

    $command->setLaravel($this->app);
    $this->app->instance(SystemDriverInterface::class, $driver);
    $tester = new CommandTester($command);

    Prompt::fallbackWhen(true);

    try {
        // Case 1: Explicit path skips prompt
        $exitExplicit = $tester->execute([
            'path' => $tempDir1,
            '--lang' => 'en',
        ], ['interactive' => true]);

        expect($exitExplicit)->toBe(0)
            ->and($command->promptShown)->toBeFalse()
            ->and($recordedTargets[0])->toBe($tempDir1)
            ->and($tester->getDisplay())->toContain($tempDir1);

        // Case 2: Omitted path in interactive mode displays mounted disks + Home option and scans chosen disk #2
        $exitInteractive = $tester->execute([
            '--lang' => 'pt_BR',
        ], ['interactive' => true]);

        expect($exitInteractive)->toBe(0)
            ->and($command->promptShown)->toBeTrue()
            ->and($command->capturedOptions['1'])->toContain('48.2 GB')
            ->and($command->capturedOptions['2'])->toContain('412.0 GB')
            ->and($command->capturedOptions['3'])->toContain('Home')
            ->and($recordedTargets[1])->toBe($tempDir2)
            ->and($tester->getDisplay())->toContain($tempDir2);
    } finally {
        Prompt::fallbackWhen(false);
        @unlink($sampleFile);
        @rmdir($tempDir1);
        @rmdir($tempDir2);
    }
});

it('supports --disk flag, interactive disk selection, and read-only volume fallback notice in toolbox:benchmark', function (): void {
    $tempDisk = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_bench_vol_' . uniqid();
    $readOnlyDisk = $tempDisk . DIRECTORY_SEPARATOR . 'readonly_mount';
    mkdir($readOnlyDisk, 0777, true);

    /** @var array<int, string|null> $recordedTargets */
    $recordedTargets = [];

    $driver = makeDiskSelectorMockDriver(
        volumes: [
            ['filesystem' => 'C: (NTFS)', 'total' => '237.5 GB', 'used' => '189.3 GB', 'available' => '48.2 GB', 'usage_percent' => '79.7%', 'mount' => $tempDisk],
            ['filesystem' => 'E: (CDFS)', 'total' => '4.7 GB', 'used' => '4.7 GB', 'available' => '0 B', 'usage_percent' => '100.0%', 'mount' => $readOnlyDisk],
        ],
        onBenchmark: function (int $sizeMb, float $cpuSec, ?string $target) use (&$recordedTargets, $readOnlyDisk): ActionResult {
            $recordedTargets[] = $target;
            $fallbackUsed = ($target === $readOnlyDisk);
            $measured = $fallbackUsed ? sys_get_temp_dir() : ($target ?? sys_get_temp_dir());

            $details = [
                'target_disk' => $measured,
                'cpu_score' => '500,000 SHA-256 ops/sec',
                'disk_write_speed' => '380.0 MB/s (64 MB sample)',
                'disk_read_speed' => '900.0 MB/s (64 MB sample)',
                'storage_tier' => '🚀 NVMe / Fast SSD',
                'driver' => 'Mock',
            ];

            if ($fallbackUsed) {
                $details['fallback_from'] = $readOnlyDisk;
            }

            return new ActionResult(true, 'messages.toolbox.benchmark_success', $details);
        },
    );

    $command = new class extends BenchmarkCommand
    {
        public bool $promptShown = false;

        protected function promptNumericMenu(string $promptLabel, array $options, string $defaultKey = '1'): string
        {
            $this->promptShown = true;

            return '2'; // Select read-only mount
        }
    };

    $command->setLaravel($this->app);
    $this->app->instance(SystemDriverInterface::class, $driver);
    $tester = new CommandTester($command);

    Prompt::fallbackWhen(true);

    try {
        // Case 1: Explicit --disk option skips prompt and reports measured volume
        $exitExplicit = $tester->execute([
            '--disk' => $tempDisk,
            '--lang' => 'en',
        ], ['interactive' => true]);

        expect($exitExplicit)->toBe(0)
            ->and($command->promptShown)->toBeFalse()
            ->and($recordedTargets[0])->toBe($tempDisk)
            ->and($tester->getDisplay())->toContain($tempDisk);

        // Case 2: Omitted --disk interactively selects #2 and shows read-only fallback warning cleanly
        $exitInteractive = $tester->execute([
            '--lang' => 'pt_BR',
        ], ['interactive' => true]);

        expect($exitInteractive)->toBe(0)
            ->and($command->promptShown)->toBeTrue()
            ->and($recordedTargets[1])->toBe($readOnlyDisk)
            ->and($tester->getDisplay())->toContain('somente leitura');
    } finally {
        Prompt::fallbackWhen(false);
        @rmdir($readOnlyDisk);
        @rmdir($tempDisk);
    }
});
