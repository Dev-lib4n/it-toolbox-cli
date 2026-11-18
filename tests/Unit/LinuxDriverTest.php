<?php

declare(strict_types=1);

use App\Contracts\ProcessExecutorInterface;
use App\Drivers\LinuxDriver;
use App\DTOs\ProcessResult;

it('parses /etc/os-release, /proc/meminfo, df -P, ss -tulnp, and lpstat via ProcessExecutorInterface', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        /** @var array<string, string> */
        private array $files = [
            '/etc/os-release' => "NAME=\"Debian GNU/Linux\"\nVERSION=\"12 (bookworm)\"\nPRETTY_NAME=\"Debian GNU/Linux 12 (bookworm)\"\n",
            '/proc/sys/kernel/hostname' => "debian-srv-01\n",
            '/proc/uptime' => "90060.00 180000.00\n", // 1d 1h 1m
            '/proc/cpuinfo' => "processor\t: 0\nmodel name\t: AMD EPYC 7763 64-Core\ncpu cores\t: 4\nprocessor\t: 1\n",
            '/proc/loadavg' => "0.40 0.30 0.25 1/250 1020\n",
            '/proc/meminfo' => "MemTotal:       16777216 kB\nMemFree:         2097152 kB\nMemAvailable:    8388608 kB\n",
            '/proc/mounts' => "/dev/sda1 / ext4 rw,relatime 0 0\ntmpfs /run tmpfs rw 0 0\n",
            '/etc/resolv.conf' => "nameserver 9.9.9.9\nnameserver 1.1.1.1\n",
        ];

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            return match ($command) {
                ['uname', '-r'] => new ProcessResult(0, '6.1.0-22-amd64'),
                ['uname', '-m'] => new ProcessResult(0, 'x86_64'),
                ['df', '-P', '-B1'] => new ProcessResult(0, implode("\n", [
                    'Filesystem     1048576-blocks         Used    Available Capacity Mounted on',
                    '/dev/sda1        107374182400  94489280512  12884901888      88% /',
                    'tmpfs              4294967296      1048576   4293918720       1% /run',
                ])),
                ['which', 'ss'] => new ProcessResult(0, '/usr/bin/ss'),
                ['ss', '-tulnp'] => new ProcessResult(0, implode("\n", [
                    'Netid State  Recv-Q Send-Q Local Address:Port Peer Address:Port Process',
                    'tcp   LISTEN 0      128          0.0.0.0:22        0.0.0.0:*    users:(("sshd",pid=712,fd=3))',
                ])),
                ['which', 'lpstat'] => new ProcessResult(0, '/usr/bin/lpstat'),
                ['lpstat', '-p', '-d'] => new ProcessResult(0, "printerBrother_HL is idle. enabled\nprinter Office_CUPS is idle. enabled\nsystem default destination: Office_CUPS"),
                ['lpstat', '-o'] => new ProcessResult(0, "Office_CUPS-41 root 2048 Tue\nOffice_CUPS-42 root 4096 Tue"),
                ['lpstat', '-v'] => new ProcessResult(0, 'device for Office_CUPS: ipp://10.0.0.50/ipp/print'),
                default => new ProcessResult(0, ''),
            };
        }

        public function readFile(string $path): ?string
        {
            return $this->files[$path] ?? null;
        }

        public function getEffectiveUid(): int
        {
            return 0;
        }
    };

    $driver = new LinuxDriver($executor);

    $os = $driver->getOsInfo();
    expect($os['os_name'])->toBe('Debian GNU/Linux 12 (bookworm)')
        ->and($os['kernel'])->toBe('6.1.0-22-amd64')
        ->and($os['hostname'])->toBe('debian-srv-01')
        ->and($os['uptime'])->toBe('1d 1h 1m');

    $hw = $driver->getHardwareDiagnostics();
    expect($hw['cpu_model'])->toBe('AMD EPYC 7763 64-Core')
        ->and($hw['ram_total'])->toBe('16.00 GB')
        ->and($hw['ram_used'])->toBe('8.00 GB (50.0%)');

    $storage = $driver->getStorageHealth();
    expect($storage)->toHaveCount(1)
        ->and($storage[0]['filesystem'])->toBe('/dev/sda1 (ext4)')
        ->and($storage[0]['usage_percent'])->toContain('88.0%')
        ->and($storage[0]['usage_percent'])->toContain('[HIGH]');

    $ports = $driver->getListeningPorts();
    expect($ports)->toHaveCount(1)
        ->and($ports[0]['port'])->toBe('22')
        ->and($ports[0]['pid'])->toBe('712')
        ->and($ports[0]['process'])->toBe('sshd');

    $printers = $driver->listPrinters();
    expect($printers)->toHaveCount(1)
        ->and($printers[0]['name'])->toBe('Office_CUPS')
        ->and($printers[0]['jobs'])->toBe('2')
        ->and($printers[0]['default'])->toBe('Yes');
});

it('parses Linux critical logs from journalctl JSON and falls back to syslog/empty logs cleanly', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public int $callIndex = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->callIndex++;

            if ($this->callIndex === 1) {
                $line1 = (string) json_encode([
                    '__REALTIME_TIMESTAMP' => '1763460000000000',
                    'PRIORITY' => '2',
                    'SYSLOG_IDENTIFIER' => 'kernel',
                    'MESSAGE' => 'EXT4-fs error (device sda1): ext4_find_entry:1455',
                ]);
                $line2 = (string) json_encode([
                    '__REALTIME_TIMESTAMP' => '1763463600000000',
                    'PRIORITY' => '3',
                    'SYSLOG_IDENTIFIER' => 'sshd',
                    'MESSAGE' => 'error: PAM: Authentication failure for root',
                ]);

                return new ProcessResult(0, $line1 . "\n" . $line2);
            }

            return new ProcessResult(0, '-- No entries --');
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

    $driver = new LinuxDriver($executor);

    $logsResult = $driver->getCriticalLogs(15);
    expect($logsResult->success)->toBeTrue()
        ->and($logsResult->message)->toBe('messages.logs.fetched_success')
        ->and($logsResult->details['count'])->toBe(2)
        ->and($logsResult->details['events'][0]['level'])->toBe('CRITICAL')
        ->and($logsResult->details['events'][0]['source'])->toBe('kernel')
        ->and($logsResult->details['events'][1]['level'])->toBe('ERROR')
        ->and($logsResult->details['events'][1]['source'])->toBe('sshd');

    $emptyResult = $driver->getCriticalLogs(15);
    expect($emptyResult->success)->toBeTrue()
        ->and($emptyResult->message)->toBe('messages.logs.no_logs')
        ->and($emptyResult->details['count'])->toBe(0)
        ->and($emptyResult->details['events'])->toBeEmpty();
});

it('parses Linux nmcli Wi-Fi metrics, ip neigh LAN devices, and executes network stack reset as root', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        public int $uid = 0;

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            return match ($command) {
                ['nmcli', '-t', '-f', 'ACTIVE,SSID,BSSID,CHAN,FREQ,RATE,SIGNAL', 'dev', 'wifi'] => new ProcessResult(
                    0,
                    "no:GuestNet:11\\:22\\:33\\:44\\:55\\:66:6:2437 MHz:130 Mbit/s:55\nyes:Office-WiFi6:AA\\:BB\\:CC\\:DD\\:EE\\:FF:44:5220 MHz:540 Mbit/s:88"
                ),
                ['systemctl', 'restart', 'NetworkManager'] => new ProcessResult(0, ''),
                ['which', 'dhclient'] => new ProcessResult(0, '/usr/sbin/dhclient'),
                ['dhclient', '-r'] => new ProcessResult(0, ''),
                ['dhclient'] => new ProcessResult(0, ''),
                ['ip', 'neigh', 'show'] => new ProcessResult(0, implode("\n", [
                    '192.168.1.1 dev eth0 lladdr 00:1a:2b:3c:4d:5e REACHABLE',
                    '192.168.1.25 dev eth0 lladdr 10:20:30:40:50:60 STALE',
                    '192.168.1.99 dev eth0 FAILED',
                ])),
                default => new ProcessResult(1, '', 'Command failed', false),
            };
        }

        public function readFile(string $path): ?string
        {
            return null;
        }

        public function getEffectiveUid(): int
        {
            return $this->uid;
        }
    };

    $driver = new LinuxDriver($executor);

    $wifi = $driver->getWifiInfo();
    expect($wifi->success)->toBeTrue()
        ->and($wifi->details['ssid'])->toBe('Office-WiFi6')
        ->and($wifi->details['bssid'])->toBe('AA:BB:CC:DD:EE:FF')
        ->and($wifi->details['signal'])->toBe('88%')
        ->and($wifi->details['channel'])->toBe('44');

    $resetRoot = $driver->resetNetworkStack();
    expect($resetRoot->success)->toBeTrue()
        ->and($resetRoot->details['commands_executed'])->toContain('systemctl restart NetworkManager', 'dhclient -r && dhclient');

    $executor->uid = 1000;
    $resetNonRoot = $driver->resetNetworkStack();
    expect($resetNonRoot->success)->toBeFalse()
        ->and($resetNonRoot->message)->toBe('messages.common.permission_denied');

    $neighbors = $driver->scanLanDevices();
    expect($neighbors)->toHaveCount(2)
        ->and($neighbors[0]['ip'])->toBe('192.168.1.1')
        ->and($neighbors[0]['mac'])->toBe('00:1A:2B:3C:4D:5E')
        ->and($neighbors[0]['status'])->toBe('REACHABLE')
        ->and($neighbors[1]['ip'])->toBe('192.168.1.25');
});

it('parses Linux critical services, /sys battery telemetry, systemd startup units, lsusb devices, and killProcessByName', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        /** @var array<string, string> */
        public array $files = [
            '/sys/class/power_supply/BAT0/status' => "Discharging\n",
            '/sys/class/power_supply/BAT0/capacity' => "76\n",
            '/sys/class/power_supply/BAT0/energy_full_design' => "60000000\n", // 60,000 mWh
            '/sys/class/power_supply/BAT0/energy_full' => "51000000\n",        // 51,000 mWh (85% health, 15% wear)
            '/sys/class/power_supply/BAT0/cycle_count' => "215\n",
        ];

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            if ($command[0] === 'systemctl' && ($command[1] ?? '') === 'show') {
                return new ProcessResult(0, implode("\n", [
                    'Id=cups.service',
                    'Description=CUPS Scheduler',
                    'LoadState=loaded',
                    'ActiveState=active',
                    'UnitFileState=enabled',
                    '',
                    'Id=nginx.service',
                    'Description=A high performance web server',
                    'LoadState=loaded',
                    'ActiveState=inactive',
                    'UnitFileState=disabled',
                ]));
            }

            return match ($command) {
                ['systemctl', 'restart', 'cups'] => new ProcessResult(0, ''),
                ['systemctl', 'list-unit-files', '--state=enabled', '--type=service', '--no-pager', '--no-legend'] => new ProcessResult(
                    0,
                    "cups.service enabled enabled\nssh.service enabled enabled\n"
                ),
                ['lsusb'] => new ProcessResult(
                    0,
                    "Bus 001 Device 002: ID 046d:c52b Logitech, Inc. Unifying Receiver\nBus 002 Device 001: ID 1d6b:0003 Linux Foundation 3.0 root hub\n"
                ),
                ['pkill', '-15', '-x', 'firefox'] => new ProcessResult(0, ''),
                default => new ProcessResult(1, '', 'Failed', false),
            };
        }

        public function readFile(string $path): ?string
        {
            return $this->files[$path] ?? null;
        }

        public function getEffectiveUid(): int
        {
            return 0;
        }
    };

    $driver = new LinuxDriver($executor);

    $services = $driver->getCriticalServices();
    expect($services)->toHaveCount(2)
        ->and($services[0]['name'])->toBe('cups')
        ->and($services[0]['status'])->toBe('Running')
        ->and($services[1]['name'])->toBe('nginx')
        ->and($services[1]['status'])->toBe('Stopped');

    $restart = $driver->restartService('cups');
    expect($restart->success)->toBeTrue()
        ->and($restart->message)->toBe('messages.system.service_restarted_success');

    $battery = $driver->getBatteryHealth();
    expect($battery->success)->toBeTrue()
        ->and($battery->details['charge_percent'])->toBe('76%')
        ->and($battery->details['battery_health'])->toBe('85.0% (Wear: 15.0%)')
        ->and($battery->details['cycle_count'])->toBe('215');

    $startup = $driver->getStartupPrograms();
    expect($startup)->toHaveCount(2)
        ->and($startup[0]['name'])->toBe('cups')
        ->and($startup[1]['name'])->toBe('ssh');

    $usb = $driver->getUsbDevices();
    expect($usb)->toHaveCount(2)
        ->and($usb[0]['name'])->toBe('Logitech, Inc. Unifying Receiver')
        ->and($usb[0]['manufacturer'])->toBe('ID 046d:c52b');

    $killByName = $driver->killProcessByName('firefox');
    expect($killByName->success)->toBeTrue()
        ->and($killByName->details['process_name'])->toBe('firefox');
});

it('executes Linux package integrity audit, pending APT updates, cron/systemd scheduler, security posture audit, local users, and Samba/NFS shares', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        /** @var array<string, string> */
        public array $files = [
            '/etc/crontab' => "0 3 * * * root /usr/local/bin/backup.sh\n",
            '/sys/module/apparmor/parameters/enabled' => "Y\n",
            '/etc/ssh/sshd_config' => "PermitRootLogin no\n",
            '/etc/passwd' => "root:x:0:0:root:/root:/bin/bash\nadminuser:x:1000:1000:Admin:/home/adminuser:/bin/bash\nwww-data:x:33:33:www-data:/var/www:/usr/sbin/nologin\n",
            '/etc/group' => "sudo:x:27:adminuser\n",
            '/etc/samba/smb.conf' => "[global]\nworkgroup = WORKGROUP\n[ projects ]\npath = /srv/samba/projects\ncomment = Engineering Files\n",
            '/etc/exports' => "/srv/nfs/backups 192.168.1.0/24(rw,sync,no_subtree_check)\n",
        ];

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            return match ($command) {
                ['which', 'dpkg'] => new ProcessResult(0, '/usr/bin/dpkg'),
                ['dpkg', '--audit'] => new ProcessResult(0, ''),
                ['which', 'apt-get'] => new ProcessResult(0, '/usr/bin/apt-get'),
                ['apt-get', 'check'] => new ProcessResult(0, ''),
                ['which', 'apt'] => new ProcessResult(0, '/usr/bin/apt'),
                ['apt', 'list', '--upgradable'] => new ProcessResult(
                    0,
                    "Listing... Done\nopenssl/noble-security 3.0.13-0ubuntu3.4 amd64 [upgradable from: 3.0.13-0ubuntu3.1]\n"
                ),
                ['systemctl', 'list-timers', '--no-pager', '--no-legend'] => new ProcessResult(
                    0,
                    "Thu 2026-11-19 06:00:00 UTC 14h left apt-daily.timer apt-daily.service\n"
                ),
                ['which', 'ufw'] => new ProcessResult(0, '/usr/sbin/ufw'),
                ['ufw', 'status'] => new ProcessResult(0, "Status: active\n"),
                ['which', 'getenforce'] => new ProcessResult(1, '', '', false),
                default => new ProcessResult(1, '', '', false),
            };
        }

        public function readFile(string $path): ?string
        {
            return $this->files[$path] ?? null;
        }

        public function getEffectiveUid(): int
        {
            return 0;
        }
    };

    $driver = new LinuxDriver($executor);

    $repair = $driver->repairOsIntegrity();
    expect($repair->success)->toBeTrue()
        ->and($repair->details['commands_executed'])->toContain('dpkg --audit', 'apt-get check');

    $updates = $driver->getPendingUpdates();
    expect($updates)->toHaveCount(1)
        ->and($updates[0]['package'])->toBe('openssl')
        ->and($updates[0]['version'])->toBe('3.0.13-0ubuntu3.4');

    $tasks = $driver->getScheduledTasks();
    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]['name'])->toBe('/usr/local/bin/backup.sh')
        ->and($tasks[1]['name'])->toBe('apt-daily.timer');

    $security = $driver->runSecurityAudit();
    expect($security)->toHaveCount(3)
        ->and($security[0]['status'])->toBe('ENABLED')
        ->and($security[1]['status'])->toBe('PROTECTED')
        ->and($security[2]['status'])->toBe('HARDENED');

    $users = $driver->getLocalUsersAudit();
    expect($users)->toHaveCount(2)
        ->and($users[0]['username'])->toContain('root')
        ->and($users[1]['username'])->toContain('adminuser')
        ->and($users[1]['is_admin'])->toContain('YES');

    $shares = $driver->getNetworkShares();
    expect($shares)->toHaveCount(2)
        ->and($shares[0]['name'])->toBe('projects')
        ->and($shares[0]['type'])->toBe('Samba (SMB)')
        ->and($shares[1]['type'])->toBe('NFS Export');
});

it('handles Linux CUPS test page, default printer, CPU/Disk micro-benchmark, and DMI hardware inventory', function (): void {
    $executor = new class implements ProcessExecutorInterface
    {
        /** @var array<string, string> */
        public array $files = [
            '/etc/os-release' => "PRETTY_NAME=\"Ubuntu 24.04.1 LTS\"\nVERSION=\"24.04\"\n",
            '/proc/sys/kernel/hostname' => "ubuntu-asset-01\n",
            '/proc/uptime' => "3600.00 7200.00\n",
            '/proc/cpuinfo' => "processor\t: 0\nmodel name\t: AMD Ryzen 9 7950X\ncpu cores\t: 16\n",
            '/proc/loadavg' => "0.15 0.10 0.05 1/100 500\n",
            '/proc/meminfo' => "MemTotal:       33554432 kB\nMemAvailable:   25165824 kB\n",
            '/etc/resolv.conf' => "nameserver 1.1.1.1\n",
            '/sys/class/dmi/id/sys_vendor' => "LENOVO\n",
            '/sys/class/dmi/id/product_name' => "ThinkPad P16 Gen 2\n",
            '/sys/class/dmi/id/product_serial' => "PF4X9988\n",
            '/sys/class/dmi/id/bios_version' => "N3XET50W\n",
            '/sys/class/dmi/id/board_vendor' => "LENOVO\n",
            '/sys/class/dmi/id/board_name' => "21FA0011BR\n",
        ];

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            if ($command[0] === 'lp') {
                return new ProcessResult(0, 'request id is Office_CUPS-77 (1 file(s))');
            }

            return match ($command) {
                ['lpoptions', '-d', 'Office_CUPS'] => new ProcessResult(0, ''),
                ['uname', '-r'] => new ProcessResult(0, '6.8.0-45-generic'),
                ['uname', '-m'] => new ProcessResult(0, 'x86_64'),
                ['ip', '-j', 'route'] => new ProcessResult(0, json_encode([['dst' => 'default', 'gateway' => '192.168.1.1', 'dev' => 'eth0']])),
                ['ip', '-j', 'addr'] => new ProcessResult(0, json_encode([[
                    'ifname' => 'eth0',
                    'operstate' => 'UP',
                    'address' => 'aa:bb:cc:dd:ee:ff',
                    'addr_info' => [['family' => 'inet', 'local' => '192.168.1.50', 'prefixlen' => 24]],
                ]])),
                ['lsblk', '-d', '-b', '-o', 'NAME,SIZE,ROTA,TRAN,MODEL', '-J'] => new ProcessResult(0, json_encode([
                    'blockdevices' => [
                        ['name' => 'nvme0n1', 'size' => 1024209543168, 'rota' => false, 'tran' => 'nvme', 'model' => 'WD Black SN850X 1TB'],
                    ],
                ])),
                default => new ProcessResult(1, '', '', false),
            };
        }

        public function readFile(string $path): ?string
        {
            return $this->files[$path] ?? null;
        }

        public function getEffectiveUid(): int
        {
            return 1000;
        }
    };

    $driver = new LinuxDriver($executor);

    $testPage = $driver->printTestPage('Office_CUPS');
    expect($testPage->success)->toBeTrue()
        ->and($testPage->message)->toBe('messages.printer.test_page_success');

    $defaultSet = $driver->setDefaultPrinter('Office_CUPS');
    expect($defaultSet->success)->toBeTrue()
        ->and($defaultSet->message)->toBe('messages.printer.default_set_success');

    $inventory = $driver->getHardwareInventory();
    expect($inventory['system_identity']['hostname'])->toBe('ubuntu-asset-01')
        ->and($inventory['system_identity']['manufacturer'])->toBe('LENOVO')
        ->and($inventory['system_identity']['serial_number'])->toBe('PF4X9988')
        ->and($inventory['processor_memory']['ram_total'])->toBe('32.00 GB')
        ->and($inventory['physical_disks'])->toHaveCount(1)
        ->and($inventory['physical_disks'][0]['media_type'])->toBe('SSD / NVMe');

    $bench = $driver->runSystemBenchmark(1, 0.05);
    expect($bench->success)->toBeTrue()
        ->and($bench->message)->toBe('messages.toolbox.benchmark_success')
        ->and($bench->details['cpu_score'])->toContain('SHA-256 ops/sec');
});

it('scans Linux directories with bounded heap and skips symlinks and unreadable paths safely', function (): void {
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
            return 1000;
        }
    };

    $driver = new LinuxDriver($executor);

    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_lin_lf_' . uniqid();
    $nestedDir = $tempDir . DIRECTORY_SEPARATOR . 'nested';
    $gitDir = $tempDir . DIRECTORY_SEPARATOR . '.git';
    $lockedDir = $tempDir . DIRECTORY_SEPARATOR . 'locked';

    mkdir($nestedDir, 0755, true);
    mkdir($gitDir, 0755, true);
    mkdir($lockedDir, 0755, true);

    $isNonRootUnix = PHP_OS_FAMILY !== 'Windows' && (! function_exists('posix_geteuid') || posix_geteuid() !== 0);

    file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'a.log', str_repeat('a', 512));
    file_put_contents($nestedDir . DIRECTORY_SEPARATOR . 'b.iso', str_repeat('b', 5000));
    file_put_contents($nestedDir . DIRECTORY_SEPARATOR . 'c.tar', str_repeat('c', 3500));
    file_put_contents($nestedDir . DIRECTORY_SEPARATOR . 'd.bin', str_repeat('d', 1024));
    file_put_contents($gitDir . DIRECTORY_SEPARATOR . 'ignored_pack.bin', str_repeat('g', 9000));
    file_put_contents($lockedDir . DIRECTORY_SEPARATOR . 'secret.bin', str_repeat('l', $isNonRootUnix ? 9000 : 256));

    $symlinkPath = $tempDir . DIRECTORY_SEPARATOR . 'b_alias.iso';
    $symlinkCreated = @symlink($nestedDir . DIRECTORY_SEPARATOR . 'b.iso', $symlinkPath);
    $danglingLinkPath = $tempDir . DIRECTORY_SEPARATOR . 'dangling_link.bin';
    $danglingCreated = @symlink($tempDir . DIRECTORY_SEPARATOR . 'missing_target.bin', $danglingLinkPath);
    if ($isNonRootUnix) {
        @chmod($lockedDir, 0000);
    }

    try {
        $topTwo = $driver->findLargeFiles($tempDir, 2);

        expect($topTwo)->toHaveCount(2)
            ->and(basename($topTwo[0]['path']))->toBe('b.iso')
            ->and($topTwo[0]['size_bytes'])->toBe(5000.0)
            ->and(basename($topTwo[1]['path']))->toBe('c.tar')
            ->and($topTwo[1]['size_bytes'])->toBe(3500.0)
            ->and($driver->findLargeFiles($tempDir . DIRECTORY_SEPARATOR . 'missing_dir', 3))->toBe([]);

        if ($symlinkCreated) {
            expect(array_map(fn (array $item): string => basename($item['path']), $topTwo))
                ->not->toContain('b_alias.iso');
        }
    } finally {
        @chmod($lockedDir, 0755);
        if ($symlinkCreated) {
            @unlink($symlinkPath);
        }
        if ($danglingCreated) {
            @unlink($danglingLinkPath);
        }
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'a.log');
        @unlink($nestedDir . DIRECTORY_SEPARATOR . 'b.iso');
        @unlink($nestedDir . DIRECTORY_SEPARATOR . 'c.tar');
        @unlink($nestedDir . DIRECTORY_SEPARATOR . 'd.bin');
        @unlink($gitDir . DIRECTORY_SEPARATOR . 'ignored_pack.bin');
        @unlink($lockedDir . DIRECTORY_SEPARATOR . 'secret.bin');
        @rmdir($lockedDir);
        @rmdir($gitDir);
        @rmdir($nestedDir);
        @rmdir($tempDir);
    }
});
