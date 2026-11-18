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

it('parses Windows Wi-Fi metrics, ARP LAN devices, and executes network stack reset with Admin check', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            return match ($this->callIndex) {
                1 => new ProcessResult(0, implode("\r\n", [
                    '    Name                   : Wi-Fi',
                    '    State                  : connected',
                    '    SSID                   : CorpNet-5G',
                    '    BSSID                  : aa:bb:cc:dd:ee:ff',
                    '    Radio type             : 802.11ax',
                    '    Channel                : 36',
                    '    Receive rate (Mbps)    : 866.7',
                    '    Transmit rate (Mbps)   : 866.7',
                    '    Signal                 : 92%',
                ])),
                2 => new ProcessResult(0, 'There is no wireless interface on the system.'),
                3 => new ProcessResult(1, '', 'Access is denied.', false), // net session not admin
                4 => new ProcessResult(0, 'The command completed successfully.'), // net session admin
                5, 6, 7, 8, 9 => new ProcessResult(0, 'OK'),
                10 => new ProcessResult(0, implode("\r\n", [
                    'Interface: 192.168.1.105 --- 0xc',
                    '  Internet Address      Physical Address      Type',
                    '  192.168.1.1           00-1a-2b-3c-4d-5e     dynamic',
                    '  192.168.1.50          11-22-33-44-55-66     dynamic',
                    '  224.0.0.22            01-00-5e-00-00-16     static',
                    '  255.255.255.255       ff-ff-ff-ff-ff-ff     static',
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
            return 0;
        }
    };

    $driver = new WindowsDriver($executor);

    $wifi = $driver->getWifiInfo();
    expect($wifi->success)->toBeTrue()
        ->and($wifi->details['ssid'])->toBe('CorpNet-5G')
        ->and($wifi->details['bssid'])->toBe('aa:bb:cc:dd:ee:ff')
        ->and($wifi->details['signal'])->toBe('92%')
        ->and($wifi->details['channel'])->toBe('36')
        ->and($wifi->details['rx_rate'])->toBe('866.7 Mbps');

    $noWifi = $driver->getWifiInfo();
    expect($noWifi->success)->toBeFalse()
        ->and($noWifi->message)->toBe('messages.network.no_wifi');

    $deniedReset = $driver->resetNetworkStack();
    expect($deniedReset->success)->toBeFalse()
        ->and($deniedReset->message)->toBe('messages.common.permission_denied');

    $adminReset = $driver->resetNetworkStack();
    expect($adminReset->success)->toBeTrue()
        ->and($adminReset->message)->toBe('messages.network.reset_success')
        ->and($adminReset->details['commands_executed'])->toHaveCount(5);

    $lanDevices = $driver->scanLanDevices();
    expect($lanDevices)->toHaveCount(2)
        ->and($lanDevices[0]['ip'])->toBe('192.168.1.1')
        ->and($lanDevices[0]['mac'])->toBe('00:1A:2B:3C:4D:5E')
        ->and($lanDevices[1]['ip'])->toBe('192.168.1.50');
});

it('parses Windows critical services, battery health, startup items, USB devices, PATH audit, and killProcessByName', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            return match ($this->callIndex) {
                1 => new ProcessResult(0, (string) json_encode([
                    [
                        'Name' => 'Spooler',
                        'DisplayName' => 'Print Spooler',
                        'Status' => 'Running',
                        'StartType' => 'Automatic',
                    ],
                ])),
                2 => new ProcessResult(0, ''), // Restart-Service Spooler
                3 => new ProcessResult(0, (string) json_encode([
                    'HasBattery' => true,
                    'Name' => 'DELL 0V9H2',
                    'BatteryStatus' => 2,
                    'ChargePercent' => 84,
                    'DesignCapacity' => 50000,
                    'FullChargedCapacity' => 45000,
                    'CycleCount' => 120,
                ])),
                4 => new ProcessResult(0, (string) json_encode([
                    'HasBattery' => false,
                ])),
                5 => new ProcessResult(0, (string) json_encode([
                    [
                        'Name' => 'OneDrive',
                        'Command' => 'C:\\Program Files\\Microsoft OneDrive\\OneDrive.exe /background',
                        'Location' => 'HKCU\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run',
                        'User' => 'CORP\\User',
                    ],
                ])),
                6 => new ProcessResult(0, (string) json_encode([
                    [
                        'FriendlyName' => 'USB Root Hub (USB 3.0)',
                        'Manufacturer' => '(Standard USB HUBs)',
                        'Status' => 'OK',
                        'InstanceId' => 'USB\\ROOT_HUB30\\4&1A2B3C',
                    ],
                ])),
                7 => new ProcessResult(0, ''), // Stop-Process -Name 'notepad'
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

    $services = $driver->getCriticalServices();
    expect($services)->toHaveCount(1)
        ->and($services[0]['name'])->toBe('Spooler')
        ->and($services[0]['status'])->toBe('Running');

    $restart = $driver->restartService('Spooler');
    expect($restart->success)->toBeTrue()
        ->and($restart->message)->toBe('messages.system.service_restarted_success');

    $battery = $driver->getBatteryHealth();
    expect($battery->success)->toBeTrue()
        ->and($battery->details['charge_percent'])->toBe('84%')
        ->and($battery->details['battery_health'])->toBe('90.0% (Wear: 10.0%)')
        ->and($battery->details['cycle_count'])->toBe('120');

    $noBattery = $driver->getBatteryHealth();
    expect($noBattery->success)->toBeFalse()
        ->and($noBattery->message)->toBe('messages.system.no_battery');

    $startup = $driver->getStartupPrograms();
    expect($startup)->toHaveCount(1)
        ->and($startup[0]['name'])->toBe('OneDrive');

    $usb = $driver->getUsbDevices();
    expect($usb)->toHaveCount(1)
        ->and($usb[0]['name'])->toBe('USB Root Hub (USB 3.0)');

    $killByName = $driver->killProcessByName('notepad.exe');
    expect($killByName->success)->toBeTrue()
        ->and($killByName->details['process_name'])->toBe('notepad');

    $validDir = sys_get_temp_dir();
    $pathAudit = $driver->auditEnvironmentPath($validDir . ';C:\\NonExistent_Toolbox_Dir_999;' . $validDir);
    expect($pathAudit['summary']['valid'])->toBe(1)
        ->and($pathAudit['summary']['broken'])->toBe(1)
        ->and($pathAudit['summary']['duplicate'])->toBe(1);
});

it('executes Windows OS repair, pending updates, scheduled tasks, security audit, users audit, SMB shares, large files, browser cache cleanup, and hosts inspection', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            return match ($this->callIndex) {
                1 => new ProcessResult(0, 'Admin OK'), // net session
                2 => new ProcessResult(0, 'Windows Resource Protection did not find any integrity violations.'), // sfc
                3 => new ProcessResult(0, 'The restore operation completed successfully.'), // DISM
                4 => new ProcessResult(0, (string) json_encode([
                    [
                        'Package' => '2026-11 Cumulative Update for Windows 11 (KB5046617)',
                        'Version' => 'KB5046617',
                        'Source' => 'Windows Update',
                        'Status' => 'Available',
                    ],
                ])),
                5 => new ProcessResult(0, (string) json_encode([
                    [
                        'TaskName' => 'GoogleUpdateTaskMachineCore',
                        'TaskPath' => '\\GoogleSystem\\GoogleUpdater\\',
                        'NextRun' => '2026-11-19 08:00',
                        'State' => 'Ready',
                    ],
                ])),
                6 => new ProcessResult(0, (string) json_encode([
                    'FirewallAllOn' => true,
                    'FirewallSummary' => 'Domain: ON, Private: ON, Public: ON',
                    'AvEnabled' => true,
                    'RtpEnabled' => true,
                    'UacEnabled' => true,
                ])),
                7 => new ProcessResult(0, (string) json_encode([
                    [
                        'Name' => 'Administrator',
                        'Enabled' => true,
                        'IsAdmin' => true,
                        'LastLogon' => '2026-11-18 09:00',
                    ],
                ])),
                8 => new ProcessResult(0, (string) json_encode([
                    [
                        'Name' => 'PublicDocs',
                        'Path' => 'C:\\Users\\Public\\Documents',
                        'ShareType' => 'FileSystemVolume',
                        'Description' => 'Shared Documents',
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

    $repair = $driver->repairOsIntegrity();
    expect($repair->success)->toBeTrue()
        ->and($repair->details['commands_executed'])->toContain('sfc /scannow', 'DISM /Online /Cleanup-Image /RestoreHealth');

    $updates = $driver->getPendingUpdates();
    expect($updates)->toHaveCount(1)
        ->and($updates[0]['version'])->toBe('KB5046617');

    $tasks = $driver->getScheduledTasks();
    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]['name'])->toBe('GoogleUpdateTaskMachineCore');

    $security = $driver->runSecurityAudit();
    expect($security)->toHaveCount(3)
        ->and($security[0]['status'])->toBe('ENABLED')
        ->and($security[1]['status'])->toBe('PROTECTED')
        ->and($security[2]['status'])->toBe('ENABLED');

    $users = $driver->getLocalUsersAudit();
    expect($users)->toHaveCount(1)
        ->and($users[0]['username'])->toBe('Administrator')
        ->and($users[0]['is_admin'])->toContain('YES');

    $shares = $driver->getNetworkShares();
    expect($shares)->toHaveCount(1)
        ->and($shares[0]['name'])->toBe('PublicDocs');

    $hosts = $driver->inspectHostsFile("# Comment line\n127.0.0.1 localhost\n127.0.0.1 myapp.local\n203.0.113.55 login.bank.com\n");
    expect($hosts)->toHaveCount(3)
        ->and($hosts[0]['status'])->toBe('STANDARD')
        ->and($hosts[1]['status'])->toBe('CUSTOM_LOCAL')
        ->and($hosts[2]['status'])->toBe('EXTERNAL_REDIRECT');

    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_maint_' . uniqid();
    $cacheDir = $tempDir . DIRECTORY_SEPARATOR . 'chrome_cache';
    mkdir($cacheDir, 0777, true);
    file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'small.bin', str_repeat('A', 128));
    file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'large.bin', str_repeat('B', 2048));
    file_put_contents($cacheDir . DIRECTORY_SEPARATOR . 'data_1', str_repeat('C', 1024));

    try {
        $largeFiles = $driver->findLargeFiles($tempDir, 5);
        expect($largeFiles)->not->toBeEmpty()
            ->and(basename($largeFiles[0]['path']))->toBe('large.bin');

        $browserClean = $driver->cleanBrowserCaches(['Google Chrome' => $cacheDir]);
        expect($browserClean->success)->toBeTrue()
            ->and($browserClean->details['deleted_files'])->toBe(1)
            ->and($browserClean->details['freed_space'])->toBe('1.00 KB');
    } finally {
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'small.bin');
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'large.bin');
        @unlink($cacheDir . DIRECTORY_SEPARATOR . 'data_1');
        @rmdir($cacheDir);
        @rmdir($tempDir);
    }
});

it('handles Windows printer test page, default printer, CPU/Disk micro-benchmark, and hardware asset inventory', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            return match ($this->callIndex) {
                1 => new ProcessResult(0, (string) json_encode(['Success' => true, 'ReturnValue' => 0, 'Error' => ''])),
                2 => new ProcessResult(0, (string) json_encode(['Success' => true, 'ReturnValue' => 0, 'Error' => ''])),
                3 => new ProcessResult(0, (string) json_encode([
                    'Hostname' => 'ASSET-WS-042',
                    'Manufacturer' => 'Dell Inc.',
                    'Model' => 'OptiPlex 7010',
                    'SerialNumber' => 'SN-99887766',
                    'BiosVersion' => '1.18.0',
                    'Motherboard' => 'Dell Inc. 0X88Y',
                    'OsName' => 'Microsoft Windows 11 Pro',
                    'OsBuild' => '10.0.22631 (Build 22631)',
                    'CpuModel' => 'Intel(R) Core(TM) i7-13700',
                    'CpuCores' => '16 Cores / 24 Threads',
                    'TotalRamBytes' => 34359738368, // 32 GB
                    'RamModules' => '16.0GB @ 4800MHz, 16.0GB @ 4800MHz',
                    'Disks' => [
                        [
                            'Model' => 'Samsung PM9A1 NVMe 512GB',
                            'Size' => 512110190592,
                            'MediaType' => 'SSD',
                            'BusType' => 'NVMe',
                        ],
                    ],
                ])),
                4 => new ProcessResult(0, (string) json_encode([
                    [
                        'InterfaceAlias' => 'Ethernet',
                        'IPv4' => '10.0.10.42',
                        'IPv6' => '',
                        'Gateway' => '10.0.10.1',
                        'DnsServers' => '10.0.10.2',
                        'MacAddress' => '00-1A-2B-3C-4D-5E',
                        'Status' => 'Up',
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

    $testPage = $driver->printTestPage('HP LaserJet M428');
    expect($testPage->success)->toBeTrue()
        ->and($testPage->message)->toBe('messages.printer.test_page_success');

    $defaultSet = $driver->setDefaultPrinter('HP LaserJet M428');
    expect($defaultSet->success)->toBeTrue()
        ->and($defaultSet->message)->toBe('messages.printer.default_set_success');

    $inventory = $driver->getHardwareInventory();
    expect($inventory['system_identity']['hostname'])->toBe('ASSET-WS-042')
        ->and($inventory['system_identity']['serial_number'])->toBe('SN-99887766')
        ->and($inventory['processor_memory']['ram_total'])->toBe('32.00 GB')
        ->and($inventory['physical_disks'])->toHaveCount(1)
        ->and($inventory['physical_disks'][0]['bus_type'])->toBe('NVMe')
        ->and($inventory['network_adapters'][0]['mac'])->toBe('00-1A-2B-3C-4D-5E');

    $bench = $driver->runSystemBenchmark(1, 0.05);
    expect($bench->success)->toBeTrue()
        ->and($bench->message)->toBe('messages.toolbox.benchmark_success')
        ->and($bench->details['cpu_score'])->toContain('SHA-256 ops/sec')
        ->and($bench->details['disk_write_speed'])->toContain('MB/s')
        ->and($bench->details['disk_read_speed'])->toContain('MB/s');
});

it('finds top N largest files via min-heap while skipping protected directories, symlinks, and missing paths', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public function run(array $command, int $timeout = 30): ProcessResult
        {
            return new ProcessResult(0, '');
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

    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_win_lf_' . uniqid();
    $subDir = $tempDir . DIRECTORY_SEPARATOR . 'nested';
    $recycleDir = $tempDir . DIRECTORY_SEPARATOR . '$RECYCLE.BIN';
    $sysVolDir = $tempDir . DIRECTORY_SEPARATOR . 'System Volume Information';
    $winSxsDir = $tempDir . DIRECTORY_SEPARATOR . 'Windows' . DIRECTORY_SEPARATOR . 'WinSxS';

    mkdir($subDir, 0777, true);
    mkdir($recycleDir, 0777, true);
    mkdir($sysVolDir, 0777, true);
    mkdir($winSxsDir, 0777, true);

    file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'tiny.bin', str_repeat('a', 100));
    file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'medium.bin', str_repeat('b', 1500));
    file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'big1.bin', str_repeat('c', 4096));
    file_put_contents($subDir . DIRECTORY_SEPARATOR . 'big2.bin', str_repeat('d', 3072));
    file_put_contents($subDir . DIRECTORY_SEPARATOR . 'small2.bin', str_repeat('e', 200));
    file_put_contents($recycleDir . DIRECTORY_SEPARATOR . 'ignored_recycle.bin', str_repeat('r', 8192));
    file_put_contents($sysVolDir . DIRECTORY_SEPARATOR . 'ignored_sysvol.bin', str_repeat('s', 8192));
    file_put_contents($winSxsDir . DIRECTORY_SEPARATOR . 'ignored_winsxs.bin', str_repeat('w', 8192));

    $symlinkPath = $tempDir . DIRECTORY_SEPARATOR . 'big1_link.bin';
    $symlinkCreated = @symlink($tempDir . DIRECTORY_SEPARATOR . 'big1.bin', $symlinkPath);

    try {
        $topTwo = $driver->findLargeFiles($tempDir, 2);

        expect($topTwo)->toHaveCount(2)
            ->and(basename($topTwo[0]['path']))->toBe('big1.bin')
            ->and($topTwo[0]['size_bytes'])->toBe(4096.0)
            ->and(basename($topTwo[1]['path']))->toBe('big2.bin')
            ->and($topTwo[1]['size_bytes'])->toBe(3072.0)
            ->and($driver->findLargeFiles($tempDir . DIRECTORY_SEPARATOR . 'does_not_exist', 5))->toBe([]);

        if ($symlinkCreated) {
            expect(array_map(fn (array $item): string => basename($item['path']), $topTwo))
                ->not->toContain('big1_link.bin');
        }
    } finally {
        if ($symlinkCreated) {
            @unlink($symlinkPath);
        }
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'tiny.bin');
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'medium.bin');
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'big1.bin');
        @unlink($subDir . DIRECTORY_SEPARATOR . 'big2.bin');
        @unlink($subDir . DIRECTORY_SEPARATOR . 'small2.bin');
        @unlink($recycleDir . DIRECTORY_SEPARATOR . 'ignored_recycle.bin');
        @unlink($sysVolDir . DIRECTORY_SEPARATOR . 'ignored_sysvol.bin');
        @unlink($winSxsDir . DIRECTORY_SEPARATOR . 'ignored_winsxs.bin');
        @rmdir($winSxsDir);
        @rmdir($tempDir . DIRECTORY_SEPARATOR . 'Windows');
        @rmdir($sysVolDir);
        @rmdir($recycleDir);
        @rmdir($subDir);
        @rmdir($tempDir);
    }
});
