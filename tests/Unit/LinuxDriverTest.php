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
