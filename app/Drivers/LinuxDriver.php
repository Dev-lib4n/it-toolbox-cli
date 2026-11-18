<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Contracts\SystemDriverInterface;
use App\DTOs\ActionResult;

class LinuxDriver implements SystemDriverInterface
{
    /**
     * {@inheritDoc}
     */
    public function getOsInfo(): array
    {
        return [
            'os_name' => 'Ubuntu 24.04.1 LTS (Stub)',
            'os_version' => '24.04 (Noble Numbat)',
            'kernel' => '6.8.0-45-generic',
            'architecture' => 'x86_64',
            'hostname' => gethostname() ?: 'linux-workstation',
            'uptime' => '5 days, 11 hours, 32 minutes',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getHardwareDiagnostics(): array
    {
        return [
            'cpu_model' => 'AMD Ryzen 9 7950X 16-Core Processor',
            'cpu_cores' => '16 Cores / 32 Threads',
            'cpu_load' => '8.5%',
            'ram_total' => '32.00 GB',
            'ram_used' => '9.20 GB (28.8%)',
            'ram_free' => '22.80 GB',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getStorageHealth(): array
    {
        return [
            [
                'filesystem' => '/dev/nvme0n1p2 (ext4)',
                'total' => '915.32 GB',
                'used' => '284.10 GB',
                'available' => '584.65 GB',
                'usage_percent' => '32.7%',
                'mount' => '/',
            ],
            [
                'filesystem' => '/dev/nvme0n1p1 (vfat)',
                'total' => '511.00 MB',
                'used' => '6.10 MB',
                'available' => '504.90 MB',
                'usage_percent' => '1.2%',
                'mount' => '/boot/efi',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getTopProcesses(int $limit = 10): array
    {
        $processes = [
            ['pid' => 2310, 'name' => 'php-fpm', 'cpu' => '5.2%', 'memory' => '128 MB'],
            ['pid' => 1120, 'name' => 'mysqld', 'cpu' => '3.4%', 'memory' => '512 MB'],
            ['pid' => 890, 'name' => 'nginx', 'cpu' => '1.1%', 'memory' => '64 MB'],
            ['pid' => 450, 'name' => 'cupsd', 'cpu' => '0.3%', 'memory' => '24 MB'],
            ['pid' => 1, 'name' => 'systemd', 'cpu' => '0.1%', 'memory' => '16 MB'],
        ];

        return array_slice($processes, 0, $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function killProcess(int $pid): ActionResult
    {
        return new ActionResult(
            success: true,
            message: 'messages.system.process_killed_success',
            details: [
                'pid' => $pid,
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listPrinters(): array
    {
        return [
            [
                'name' => 'HP_LaserJet_M428',
                'status' => 'Idle',
                'default' => 'Yes',
                'driver' => 'CUPS+Gutenprint v5.3.4',
                'port' => 'ipp://192.168.1.50/ipp/print',
            ],
            [
                'name' => 'CUPS_PDF',
                'status' => 'Idle',
                'default' => 'No',
                'driver' => 'Generic CUPS-PDF Printer',
                'port' => 'cups-pdf:/',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function clearPrintQueue(): ActionResult
    {
        return new ActionResult(
            success: true,
            message: 'messages.printer.queue_cleared_success',
            details: [
                'service' => 'cups',
                'deleted_files' => 2,
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getNetworkInterfaces(): array
    {
        return [
            [
                'interface' => 'eth0',
                'ipv4' => '192.168.1.105/24',
                'ipv6' => 'fe80::1a2b:3c4d:5e6f:7a8b/64',
                'mac' => '00:1a:2b:3c:4d:5e',
                'status' => 'UP',
            ],
            [
                'interface' => 'wlan0',
                'ipv4' => '192.168.1.106/24',
                'ipv6' => 'fe80::9b8a:7f6e:5d4c:3b2a/64',
                'mac' => '00:1a:2b:3c:4d:5f',
                'status' => 'UP',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function runConnectivityHealthCheck(): array
    {
        return [
            [
                'target' => '192.168.1.1 (Default Gateway)',
                'check' => 'ICMP Ping',
                'status' => 'OK',
                'latency' => '0.8 ms',
            ],
            [
                'target' => '8.8.8.8 (Google Public DNS)',
                'check' => 'ICMP Ping',
                'status' => 'OK',
                'latency' => '12.4 ms',
            ],
            [
                'target' => '1.1.1.1 (Cloudflare DNS)',
                'check' => 'ICMP Ping',
                'status' => 'OK',
                'latency' => '9.2 ms',
            ],
            [
                'target' => 'cloudflare.com',
                'check' => 'DNS Resolution',
                'status' => 'OK',
                'latency' => '15.0 ms',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function flushDnsAndRenew(): ActionResult
    {
        return new ActionResult(
            success: true,
            message: 'messages.network.flush_success',
            details: [
                'commands_executed' => ['resolvectl flush-caches'],
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getListeningPorts(): array
    {
        return [
            [
                'protocol' => 'TCP',
                'local_address' => '0.0.0.0',
                'port' => '80',
                'pid' => '890',
                'process' => 'nginx',
            ],
            [
                'protocol' => 'TCP',
                'local_address' => '127.0.0.1',
                'port' => '3306',
                'pid' => '1120',
                'process' => 'mysqld',
            ],
            [
                'protocol' => 'TCP',
                'local_address' => '0.0.0.0',
                'port' => '22',
                'pid' => '720',
                'process' => 'sshd',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function cleanTempFiles(): ActionResult
    {
        return new ActionResult(
            success: true,
            message: 'messages.maintenance.clean_success',
            details: [
                'deleted_files' => 89,
                'skipped_locked_files' => 2,
                'freed_space' => '184.2 MB',
                'paths' => ['/tmp', '/var/tmp', '~/.cache/thumbnails'],
                'driver' => 'Linux',
            ],
        );
    }
}
