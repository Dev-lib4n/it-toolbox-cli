<?php

declare(strict_types=1);

use App\Contracts\ProcessExecutorInterface;
use App\Drivers\WindowsDriver;
use App\DTOs\ProcessResult;

it('parses PowerShell JSON for OS, CPU, RAM, Disks (>85% alert), Printers, and Listening Ports', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            return match ($this->callIndex) {
                1 => new ProcessResult(0, (string) json_encode([
                    'Caption' => 'Microsoft Windows 11 Pro',
                    'Version' => '10.0.22631',
                    'BuildNumber' => '22631',
                    'OSArchitecture' => '64-bit',
                    'CSName' => 'WIN-SUPPORT-01',
                    'Days' => 4,
                    'Hours' => 2,
                    'Minutes' => 15,
                ])),
                2 => new ProcessResult(0, (string) json_encode([
                    'CpuName' => 'Intel(R) Core(TM) i7-13700K',
                    'Cores' => 16,
                    'Threads' => 24,
                    'CpuLoad' => 18.5,
                    'TotalVisibleMemorySize' => 33554432, // 32 GB in KB
                    'FreePhysicalMemory' => 16777216,     // 16 GB in KB
                ])),
                3 => new ProcessResult(0, (string) json_encode([
                    [
                        'DeviceID' => 'C:',
                        'VolumeName' => 'OS',
                        'FileSystem' => 'NTFS',
                        'Size' => 107374182400,      // 100 GB
                        'FreeSpace' => 10737418240,  // 10 GB (90% used -> triggers >85% alert)
                    ],
                ])),
                4 => new ProcessResult(0, (string) json_encode([
                    'Name' => 'HP LaserJet M428',
                    'PortName' => 'IP_192.168.1.50',
                    'DriverName' => 'HP Universal PCL6',
                    'PrinterStatus' => 'Normal',
                    'Default' => true,
                    'JobCount' => 3,
                ])),
                5 => new ProcessResult(0, (string) json_encode([
                    [
                        'Protocol' => 'TCP',
                        'LocalAddress' => '0.0.0.0',
                        'LocalPort' => '443',
                        'Pid' => '4120',
                        'ProcessName' => 'nginx',
                    ],
                ])),
                default => new ProcessResult(0, '[]'),
            };
        }

        public function readFile(string $path): ?string
        {
            return null;
        }

        public function getEffectiveUid(): int
        {
            return 0;
        }
    };

    $driver = new WindowsDriver($executor);

    $os = $driver->getOsInfo();
    expect($os['os_name'])->toBe('Microsoft Windows 11 Pro')
        ->and($os['os_version'])->toBe('10.0.22631 (Build 22631)')
        ->and($os['hostname'])->toBe('WIN-SUPPORT-01')
        ->and($os['uptime'])->toBe('4d 2h 15m');

    $hw = $driver->getHardwareDiagnostics();
    expect($hw['cpu_model'])->toBe('Intel(R) Core(TM) i7-13700K')
        ->and($hw['cpu_load'])->toBe('18.5%')
        ->and($hw['ram_total'])->toBe('32.00 GB')
        ->and($hw['ram_used'])->toBe('16.00 GB (50.0%)');

    $storage = $driver->getStorageHealth();
    expect($storage)->toHaveCount(1)
        ->and($storage[0]['filesystem'])->toBe('C: (NTFS)')
        ->and($storage[0]['usage_percent'])->toContain('90.0%')
        ->and($storage[0]['usage_percent'])->toContain('[HIGH]');

    $printers = $driver->listPrinters();
    expect($printers)->toHaveCount(1)
        ->and($printers[0]['name'])->toBe('HP LaserJet M428')
        ->and($printers[0]['port'])->toBe('IP_192.168.1.50')
        ->and($printers[0]['jobs'])->toBe('3')
        ->and($printers[0]['default'])->toBe('Yes');

    $ports = $driver->getListeningPorts();
    expect($ports)->toHaveCount(1)
        ->and($ports[0]['port'])->toBe('443')
        ->and($ports[0]['pid'])->toBe('4120')
        ->and($ports[0]['process'])->toBe('nginx');
});

it('validates Administrator privilege when clearing print spooler queue on WindowsDriver', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            return match ($this->callIndex) {
                1 => new ProcessResult(0, (string) json_encode([
                    'IsAdmin' => false,
                    'Success' => false,
                    'DeletedFiles' => 0,
                    'Error' => 'Administrator privileges are required.',
                ])),
                2 => new ProcessResult(0, (string) json_encode([
                    'IsAdmin' => true,
                    'Success' => true,
                    'DeletedFiles' => 4,
                    'Error' => '',
                ])),
                default => new ProcessResult(0, ''),
            };
        }

        public function readFile(string $path): ?string
        {
            return null;
        }

        public function getEffectiveUid(): int
        {
            return 1000;
        }
    };

    $driver = new WindowsDriver($executor);

    $deniedResult = $driver->clearPrintQueue();
    expect($deniedResult->success)->toBeFalse()
        ->and($deniedResult->message)->toBe('messages.common.permission_denied');

    $adminResult = $driver->clearPrintQueue();
    expect($adminResult->success)->toBeTrue()
        ->and($adminResult->message)->toBe('messages.printer.queue_cleared_success')
        ->and($adminResult->details['deleted_files'])->toBe(4);
});

it('retrieves critical and error Windows Event Logs and handles empty logs gracefully', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            return match ($this->callIndex) {
                1 => new ProcessResult(0, (string) json_encode([
                    [
                        'TimeCreated' => '2026-11-18 09:15:00',
                        'Id' => '41',
                        'ProviderName' => 'Microsoft-Windows-Kernel-Power',
                        'Level' => 1,
                        'LevelDisplayName' => 'Critical',
                        'Message' => 'The system has rebooted without cleanly shutting down first.',
                    ],
                    [
                        'TimeCreated' => '2026-11-18 10:30:00',
                        'Id' => '1000',
                        'ProviderName' => 'Application Error',
                        'Level' => 2,
                        'LevelDisplayName' => 'Error',
                        'Message' => 'Faulting application name: spoolsv.exe',
                    ],
                ])),
                default => new ProcessResult(0, '[]'),
            };
        }

        public function readFile(string $path): ?string
        {
            return null;
        }

        public function getEffectiveUid(): int
        {
            return 0;
        }
    };

    $driver = new WindowsDriver($executor);

    $logsResult = $driver->getCriticalLogs(15);
    expect($logsResult->success)->toBeTrue()
        ->and($logsResult->message)->toBe('messages.logs.fetched_success')
        ->and($logsResult->details['count'])->toBe(2)
        ->and($logsResult->details['events'][0]['level'])->toBe('CRITICAL')
        ->and($logsResult->details['events'][0]['source'])->toBe('Microsoft-Windows-Kernel-Power (ID 41)')
        ->and($logsResult->details['events'][1]['level'])->toBe('ERROR');

    $emptyResult = $driver->getCriticalLogs(15);
    expect($emptyResult->success)->toBeTrue()
        ->and($emptyResult->message)->toBe('messages.logs.no_logs')
        ->and($emptyResult->details['count'])->toBe(0)
        ->and($emptyResult->details['events'])->toBeEmpty();
});
