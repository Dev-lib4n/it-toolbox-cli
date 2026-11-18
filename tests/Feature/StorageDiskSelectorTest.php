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
 * Create a lightweight SystemDriverInterface fake with configurable storage mounts and large-files callback.
 */
function makeDiskSelectorFakeDriver(
    array $volumes,
    ?callable $onFindLargeFiles = null,
    ?callable $onBenchmark = null,
): SystemDriverInterface {
    return new class($volumes, $onFindLargeFiles, $onBenchmark) implements SystemDriverInterface
    {
        /** @var array<int, string|null> */
        public array $recordedLargeFilesTargets = [];

        /** @var array<int, string|null> */
        public array $recordedBenchmarkTargets = [];

        /**
         * @param  array<int, array{filesystem: string, total: string, used: string, available: string, usage_percent: string, mount: string}>  $volumes
         */
        public function __construct(
            private readonly array $volumes,
            private readonly mixed $onFindLargeFiles = null,
            private readonly mixed $onBenchmark = null,
        ) {}

        public function getOsInfo(): array
        {
            return ['os_name' => 'TestOS', 'os_version' => '1.0', 'kernel' => '1.0', 'architecture' => 'x64', 'hostname' => 'test', 'uptime' => '1h'];
        }

        public function getHardwareDiagnostics(): array
        {
            return ['cpu_model' => 'TestCPU', 'cpu_cores' => '4', 'cpu_load' => '5%', 'ram_total' => '16 GB', 'ram_used' => '4 GB', 'ram_free' => '12 GB'];
        }

        public function getStorageHealth(): array
        {
            return $this->volumes;
        }

        public function getTopProcesses(int $limit = 10): array
        {
            return [];
        }

        public function killProcess(int $pid): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function killProcessByName(string $processName): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function getCriticalLogs(int $limit = 15): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function getCriticalServices(): array
        {
            return [];
        }

        public function restartService(string $serviceName): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function getBatteryHealth(): ActionResult
        {
            return new ActionResult(false, 'none');
        }

        public function getStartupPrograms(): array
        {
            return [];
        }

        public function getUsbDevices(): array
        {
            return [];
        }

        public function auditEnvironmentPath(?string $customPathString = null): array
        {
            return ['variables' => [], 'path_entries' => [], 'summary' => ['valid' => 0, 'broken' => 0, 'duplicate' => 0]];
        }

        public function listPrinters(): array
        {
            return [];
        }

        public function restartPrintSpoolerAndClearQueue(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function printTestPage(string $printerName): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function setDefaultPrinter(string $printerName): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function testNetworkConnectivity(): array
        {
            return [];
        }

        public function flushDnsAndRenewIp(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function getListeningPorts(): array
        {
            return [];
        }

        public function probePort(string $host, int $port, int $timeout = 3): array
        {
            return ['host' => $host, 'port' => $port, 'status' => 'OPEN', 'latency' => '1 ms', 'error' => ''];
        }

        public function getWifiStatus(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function resetNetworkStack(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function inspectSslCertificate(string $host, int $port = 443): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function scanLocalNetwork(?string $subnetPrefix = null): array
        {
            return [];
        }

        public function getPublicIpInfo(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function cleanTempFiles(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function repairOsIntegrity(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function findLargeFiles(?string $directory = null, int $limit = 15): array
        {
            $this->recordedLargeFilesTargets[] = $directory;

            if ($this->onFindLargeFiles !== null) {
                return ($this->onFindLargeFiles)($directory, $limit);
            }

            return [];
        }

        public function cleanBrowserCaches(?array $customCachePaths = null): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function getPendingUpdates(): array
        {
            return [];
        }

        public function getScheduledTasks(): array
        {
            return [];
        }

        public function getSecurityPosture(): array
        {
            return [];
        }

        public function getLocalUsers(): array
        {
            return [];
        }

        public function getNetworkShares(): array
        {
            return [];
        }

        public function inspectHostsFile(?string $customContent = null): array
        {
            return [];
        }

        public function runSystemBenchmark(int $diskSizeMb = 64, float $cpuDurationSeconds = 1.0, ?string $targetDirectory = null): ActionResult
        {
            $this->recordedBenchmarkTargets[] = $targetDirectory;

            if ($this->onBenchmark !== null) {
                return ($this->onBenchmark)($diskSizeMb, $cpuDurationSeconds, $targetDirectory);
            }

            $measured = $targetDirectory ?? sys_get_temp_dir();

            return new ActionResult(
                success: true,
                message: 'messages.toolbox.benchmark_success',
                details: [
                    'target_disk' => $measured,
                    'cpu_score' => '500,000 SHA-256 ops/sec',
                    'disk_write_speed' => '420.0 MB/s (64 MB sample)',
                    'disk_read_speed' => '1,100.0 MB/s (64 MB sample)',
                    'storage_tier' => '🚀 NVMe / Fast SSD',
                    'driver' => 'Test',
                ],
            );
        }

        public function getHardwareInventory(): array
        {
            return ['system_identity' => [], 'processor_memory' => [], 'physical_disks' => [], 'network_adapters' => []];
        }
    };
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

    $driver = makeDiskSelectorFakeDriver(
        volumes: [
            ['filesystem' => 'C: (NTFS)', 'total' => '237.5 GB', 'used' => '189.3 GB', 'available' => '48.2 GB', 'usage_percent' => '79.7%', 'mount' => $tempDir1],
            ['filesystem' => 'D: (NTFS)', 'total' => '931.5 GB', 'used' => '519.5 GB', 'available' => '412.0 GB', 'usage_percent' => '55.8%', 'mount' => $tempDir2],
        ],
        onFindLargeFiles: fn (?string $dir): array => $dir === $tempDir2
            ? [['path' => $sampleFile, 'size_bytes' => 2048.0, 'size_formatted' => '2.00 KB', 'modified_at' => '2026-11-18 12:00']]
            : [],
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
            ->and($driver->recordedLargeFilesTargets[0])->toBe($tempDir1)
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
            ->and($driver->recordedLargeFilesTargets[1])->toBe($tempDir2)
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

    $driver = makeDiskSelectorFakeDriver(
        volumes: [
            ['filesystem' => 'C: (NTFS)', 'total' => '237.5 GB', 'used' => '189.3 GB', 'available' => '48.2 GB', 'usage_percent' => '79.7%', 'mount' => $tempDisk],
            ['filesystem' => 'E: (CDFS)', 'total' => '4.7 GB', 'used' => '4.7 GB', 'available' => '0 B', 'usage_percent' => '100.0%', 'mount' => $readOnlyDisk],
        ],
        onBenchmark: function (int $sizeMb, float $cpuSec, ?string $target) use ($readOnlyDisk): ActionResult {
            $fallbackUsed = ($target === $readOnlyDisk);
            $measured = $fallbackUsed ? sys_get_temp_dir() : ($target ?? sys_get_temp_dir());

            $details = [
                'target_disk' => $measured,
                'cpu_score' => '500,000 SHA-256 ops/sec',
                'disk_write_speed' => '380.0 MB/s (64 MB sample)',
                'disk_read_speed' => '900.0 MB/s (64 MB sample)',
                'storage_tier' => '🚀 NVMe / Fast SSD',
                'driver' => 'Test',
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

            return '2'; // Select read-only E:\ mount
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
            ->and($driver->recordedBenchmarkTargets[0])->toBe($tempDisk)
            ->and($tester->getDisplay())->toContain($tempDisk);

        // Case 2: Omitted --disk interactively selects #2 and shows read-only fallback warning cleanly
        $exitInteractive = $tester->execute([
            '--lang' => 'pt_BR',
        ], ['interactive' => true]);

        expect($exitInteractive)->toBe(0)
            ->and($command->promptShown)->toBeTrue()
            ->and($driver->recordedBenchmarkTargets[1])->toBe($readOnlyDisk)
            ->and($tester->getDisplay())->toContain('somente leitura');
    } finally {
        Prompt::fallbackWhen(false);
        @rmdir($readOnlyDisk);
        @rmdir($tempDisk);
    }
});
