<?php

declare(strict_types=1);

use App\Contracts\ProcessRunnerInterface;
use App\Drivers\WindowsDriver;
use App\DTOs\ProcessResult;

it('parses OS, hardware, and storage health with high usage alert (>85%) on WindowsDriver', function (): void {
    $runner = new class implements ProcessRunnerInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            return match ($this->callIndex) {
                1 => new ProcessResult(0, json_encode([
                    'Caption' => 'Microsoft Windows 11 Pro',
                    'Version' => '10.0.22631',
                    'BuildNumber' => '22631',
                    'OSArchitecture' => '64-bit',
                    'CSName' => 'WIN-SUPPORT-01',
                    'Days' => 3,
                    'Hours' => 5,
                    'Minutes' => 42,
                ])),
                2 => new ProcessResult(0, json_encode([
                    'CpuName' => 'Intel(R) Core(TM) i7-13700K',
                    'Cores' => 16,
                    'Threads' => 24,
                    'CpuLoad' => 22.5,
                    'TotalVisibleMemorySize' => 33554432, // 32 GB in KB
                    'FreePhysicalMemory' => 16777216,     // 16 GB in KB
                ])),
                3 => new ProcessResult(0, json_encode([
                    [
                        'DeviceID' => 'C:',
                        'VolumeName' => 'OS',
                        'FileSystem' => 'NTFS',
                        'Size' => 107374182400,      // 100 GB
                        'FreeSpace' => 10737418240,  // 10 GB (90% used -> HIGH alert)
                    ],
                ])),
                default => new ProcessResult(0, '[]'),
            };
        }
    };

    $driver = new WindowsDriver($runner);

    $os = $driver->getOsInfo();
    expect($os['os_name'])->toBe('Microsoft Windows 11 Pro')
        ->and($os['os_version'])->toBe('10.0.22631 (Build 22631)')
        ->and($os['hostname'])->toBe('WIN-SUPPORT-01')
        ->and($os['uptime'])->toBe('3d 5h 42m');

    $hw = $driver->getHardwareDiagnostics();
    expect($hw['cpu_model'])->toBe('Intel(R) Core(TM) i7-13700K')
        ->and($hw['cpu_load'])->toBe('22.5%')
        ->and($hw['ram_total'])->toBe('32.00 GB')
        ->and($hw['ram_used'])->toBe('16.00 GB (50.0%)');

    $storage = $driver->getStorageHealth();
    expect($storage)->toHaveCount(1)
        ->and($storage[0]['filesystem'])->toBe('C: (NTFS)')
        ->and($storage[0]['usage_percent'])->toContain('90.0%')
        ->and($storage[0]['usage_percent'])->toContain('[HIGH]');
});

it('handles access denied gracefully when killing a process or clearing print queue without Administrator rights', function (): void {
    $runner = new class implements ProcessRunnerInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            return match ($this->callIndex) {
                1 => new ProcessResult(1, '', 'Stop-Process : Access is denied', false),
                2 => new ProcessResult(0, json_encode([
                    'IsAdmin' => false,
                    'Success' => false,
                    'DeletedFiles' => 0,
                    'Error' => 'Administrator privileges are required.',
                ])),
                default => new ProcessResult(0, ''),
            };
        }
    };

    $driver = new WindowsDriver($runner);

    $killResult = $driver->killProcess(4040);
    expect($killResult->success)->toBeFalse()
        ->and($killResult->message)->toBe('messages.common.permission_denied');

    $spoolResult = $driver->clearPrintQueue();
    expect($spoolResult->success)->toBeFalse()
        ->and($spoolResult->message)->toBe('messages.common.permission_denied');
});

it('cleans temporary files and flushes DNS on WindowsDriver', function (): void {
    $runner = new class implements ProcessRunnerInterface
    {
        /** @var array<int, array<int, string>> */
        public array $executedCommands = [];

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->executedCommands[] = $command;

            if ($command[0] === 'powershell') {
                return new ProcessResult(0, json_encode([
                    'FreedBytes' => 104857600, // 100 MB
                    'DeletedFiles' => 45,
                    'SkippedFiles' => 3,
                    'Targets' => ['C:\\Users\\Admin\\AppData\\Local\\Temp', 'C:\\Windows\\Temp'],
                ]));
            }

            return new ProcessResult(0, 'Windows IP Configuration: Successfully flushed the DNS Resolver Cache.');
        }
    };

    $driver = new WindowsDriver($runner);
    $result = $driver->cleanTempFiles();

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('messages.maintenance.clean_success')
        ->and($result->details['freed_space'])->toBe('100.00 MB')
        ->and($result->details['deleted_files'])->toBe(45)
        ->and($result->details['skipped_locked_files'])->toBe(3)
        ->and($runner->executedCommands)->toHaveCount(2)
        ->and($runner->executedCommands[1])->toBe(['ipconfig', '/flushdns']);
});
