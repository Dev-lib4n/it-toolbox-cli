<?php

declare(strict_types=1);

use App\Contracts\ProcessRunnerInterface;
use App\Drivers\LinuxDriver;
use App\DTOs\ProcessResult;

it('parses Linux /proc metrics, /etc/os-release, and df storage health with >85% alert', function (): void {
    $files = [
        '/etc/os-release' => "NAME=\"Fedora Linux\"\nVERSION=\"40 (Workstation Edition)\"\nPRETTY_NAME=\"Fedora Linux 40 (Workstation Edition)\"\n",
        '/proc/sys/kernel/hostname' => "fedora-workstation\n",
        '/proc/uptime' => "176400.50 350000.10\n", // 2d 1h 0m
        '/proc/cpuinfo' => "processor\t: 0\nmodel name\t: AMD Ryzen 7 7840U\ncpu cores\t: 8\nprocessor\t: 1\nmodel name\t: AMD Ryzen 7 7840U\ncpu cores\t: 8\n",
        '/proc/loadavg' => "0.50 0.40 0.35 1/800 1234\n",
        '/proc/meminfo' => "MemTotal:       16777216 kB\nMemFree:         4194304 kB\nMemAvailable:    8388608 kB\n",
        '/proc/mounts' => "/dev/nvme0n1p2 / ext4 rw,relatime 0 0\ntmpfs /run tmpfs rw,nosuid 0 0\n",
    ];

    $runner = new class implements ProcessRunnerInterface
    {
        public function run(array $command, int $timeout = 30): ProcessResult
        {
            return match ($command) {
                ['uname', '-r'] => new ProcessResult(0, '6.9.7-200.fc40.x86_64'),
                ['uname', '-m'] => new ProcessResult(0, 'x86_64'),
                ['df', '-P', '-B1'] => new ProcessResult(0, implode("\n", [
                    'Filesystem     1048576-blocks         Used    Available Capacity Mounted on',
                    '/dev/nvme0n1p2   107374182400  96636764160  10737418240      90% /',
                    'tmpfs              8589934592      1048576   8588886016       1% /run',
                ])),
                default => new ProcessResult(0, ''),
            };
        }
    };

    $driver = new LinuxDriver(
        processRunner: $runner,
        fileReader: fn (string $path): ?string => $files[$path] ?? null,
    );

    $os = $driver->getOsInfo();
    expect($os['os_name'])->toBe('Fedora Linux 40 (Workstation Edition)')
        ->and($os['kernel'])->toBe('6.9.7-200.fc40.x86_64')
        ->and($os['hostname'])->toBe('fedora-workstation')
        ->and($os['uptime'])->toBe('2d 1h 0m');

    $hw = $driver->getHardwareDiagnostics();
    expect($hw['cpu_model'])->toBe('AMD Ryzen 7 7840U')
        ->and($hw['cpu_cores'])->toBe('8 Cores / 2 Threads')
        ->and($hw['ram_total'])->toBe('16.00 GB')
        ->and($hw['ram_used'])->toBe('8.00 GB (50.0%)');

    $storage = $driver->getStorageHealth();
    expect($storage)->toHaveCount(1)
        ->and($storage[0]['filesystem'])->toBe('/dev/nvme0n1p2 (ext4)')
        ->and($storage[0]['usage_percent'])->toContain('90.0%')
        ->and($storage[0]['usage_percent'])->toContain('[HIGH]');
});

it('parses CUPS printers and clears print queue with root daemon restart on LinuxDriver', function (): void {
    $runner = new class implements ProcessRunnerInterface
    {
        /** @var array<int, array<int, string>> */
        public array $commands = [];

        public function run(array $command, int $timeout = 30): ProcessResult
        {
            $this->commands[] = $command;

            return match ($command) {
                ['which', 'lpstat'] => new ProcessResult(0, '/usr/bin/lpstat'),
                ['lpstat', '-p', '-d'] => new ProcessResult(0, "printer Office_HP is idle. enabled since Mon\nsystem default destination: Office_HP"),
                ['lpstat', '-o'] => new ProcessResult(0, "Office_HP-101 user 1024 Mon\nOffice_HP-102 user 2048 Mon"),
                ['lpstat', '-v'] => new ProcessResult(0, 'device for Office_HP: ipp://192.168.1.50/ipp/print'),
                ['which', 'cancel'] => new ProcessResult(0, '/usr/bin/cancel'),
                ['cancel', '-a'] => new ProcessResult(0, ''),
                ['which', 'systemctl'] => new ProcessResult(0, '/usr/bin/systemctl'),
                ['systemctl', 'restart', 'cups'] => new ProcessResult(0, ''),
                default => new ProcessResult(0, ''),
            };
        }
    };

    $driver = new LinuxDriver(
        processRunner: $runner,
        uidResolver: fn (): int => 0,
    );

    $printers = $driver->listPrinters();
    expect($printers)->toHaveCount(1)
        ->and($printers[0]['name'])->toBe('Office_HP')
        ->and($printers[0]['jobs'])->toBe('2')
        ->and($printers[0]['default'])->toBe('Yes')
        ->and($printers[0]['port'])->toBe('ipp://192.168.1.50/ipp/print');

    $clearResult = $driver->clearPrintQueue();
    expect($clearResult->success)->toBeTrue()
        ->and($clearResult->details['commands_executed'])->toContain('cancel -a', 'systemctl restart cups');
});

it('parses network interfaces, connectivity checks via getent, listening ports via ss, and flushes DNS on LinuxDriver', function (): void {
    $files = [
        '/etc/resolv.conf' => "nameserver 1.1.1.1\nnameserver 8.8.8.8\n",
    ];

    $runner = new class implements ProcessRunnerInterface
    {
        public function run(array $command, int $timeout = 30): ProcessResult
        {
            return match ($command) {
                ['ip', '-j', 'route'] => new ProcessResult(0, json_encode([
                    ['dst' => 'default', 'gateway' => '192.168.1.1', 'dev' => 'eth0'],
                ])),
                ['ip', '-j', 'addr'] => new ProcessResult(0, json_encode([
                    [
                        'ifname' => 'eth0',
                        'operstate' => 'UP',
                        'address' => '00:1a:2b:3c:4d:5e',
                        'addr_info' => [
                            ['family' => 'inet', 'local' => '192.168.1.100', 'prefixlen' => 24],
                        ],
                    ],
                ])),
                ['ping', '-c', '1', '-W', '2', '192.168.1.1'] => new ProcessResult(0, '64 bytes from 192.168.1.1: icmp_seq=1 ttl=64 time=0.85 ms'),
                ['ping', '-c', '1', '-W', '2', '1.1.1.1'] => new ProcessResult(0, '64 bytes from 1.1.1.1: icmp_seq=1 ttl=57 time=11.2 ms'),
                ['getent', 'hosts', 'google.com'] => new ProcessResult(0, '142.250.190.46  google.com'),
                ['which', 'resolvectl'] => new ProcessResult(0, '/usr/bin/resolvectl'),
                ['resolvectl', 'flush-caches'] => new ProcessResult(0, ''),
                ['which', 'ss'] => new ProcessResult(0, '/usr/sbin/ss'),
                ['ss', '-tulnp'] => new ProcessResult(0, implode("\n", [
                    'Netid State  Recv-Q Send-Q Local Address:Port Peer Address:Port Process',
                    'tcp   LISTEN 0      511          0.0.0.0:80        0.0.0.0:*    users:(("nginx",pid=890,fd=6))',
                ])),
                default => new ProcessResult(0, ''),
            };
        }
    };

    $driver = new LinuxDriver(
        processRunner: $runner,
        fileReader: fn (string $path): ?string => $files[$path] ?? null,
    );

    $interfaces = $driver->getNetworkInterfaces();
    expect($interfaces)->toHaveCount(1)
        ->and($interfaces[0]['interface'])->toBe('eth0')
        ->and($interfaces[0]['ipv4'])->toBe('192.168.1.100/24')
        ->and($interfaces[0]['gateway'])->toBe('192.168.1.1')
        ->and($interfaces[0]['dns'])->toBe('1.1.1.1, 8.8.8.8');

    $checks = $driver->runConnectivityHealthCheck();
    expect($checks)->toHaveCount(4)
        ->and($checks[0]['status'])->toBe('OK')
        ->and($checks[1]['latency'])->toBe('0.85 ms')
        ->and($checks[2]['latency'])->toBe('11.2 ms')
        ->and($checks[3]['check'])->toBe('DNS Resolution (getent)')
        ->and($checks[3]['status'])->toBe('OK');

    $flush = $driver->flushDnsAndRenew();
    expect($flush->success)->toBeTrue()
        ->and($flush->details['commands_executed'])->toContain('resolvectl flush-caches');

    $ports = $driver->getListeningPorts();
    expect($ports)->toHaveCount(1)
        ->and($ports[0]['port'])->toBe('80')
        ->and($ports[0]['pid'])->toBe('890')
        ->and($ports[0]['process'])->toBe('nginx');
});
