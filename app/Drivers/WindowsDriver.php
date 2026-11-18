<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Contracts\SystemDriverInterface;
use App\DTOs\ActionResult;

class WindowsDriver implements SystemDriverInterface
{
    /**
     * {@inheritDoc}
     */
    public function getOsInfo(): array
    {
        return [
            'os_name' => 'Microsoft Windows 11 Pro (Stub)',
            'os_version' => '10.0.22631 Build 22631',
            'kernel' => 'NT 10.0',
            'architecture' => 'x86_64 (64-bit)',
            'hostname' => gethostname() ?: 'WIN-WORKSTATION',
            'uptime' => '2 days, 4 hours, 15 minutes',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getHardwareDiagnostics(): array
    {
        return [
            'cpu_model' => 'Intel(R) Core(TM) i7-13700K @ 3.40GHz',
            'cpu_cores' => '16 Cores / 24 Logical Processors',
            'cpu_load' => '14%',
            'ram_total' => '32.00 GB',
            'ram_used' => '12.40 GB (38.8%)',
            'ram_free' => '19.60 GB',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getStorageHealth(): array
    {
        return [
            [
                'filesystem' => 'C: (NTFS)',
                'total' => '931.51 GB',
                'used' => '412.20 GB',
                'available' => '519.31 GB',
                'usage_percent' => '44.2%',
                'mount' => 'C:\\',
            ],
            [
                'filesystem' => 'D: (NTFS)',
                'total' => '1863.02 GB',
                'used' => '750.00 GB',
                'available' => '1113.02 GB',
                'usage_percent' => '40.3%',
                'mount' => 'D:\\',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getTopProcesses(int $limit = 10): array
    {
        $processes = [
            ['pid' => 4120, 'name' => 'chrome.exe', 'cpu' => '8.4%', 'memory' => '1,240 MB'],
            ['pid' => 8832, 'name' => 'php.exe', 'cpu' => '3.1%', 'memory' => '64 MB'],
            ['pid' => 1540, 'name' => 'explorer.exe', 'cpu' => '1.8%', 'memory' => '210 MB'],
            ['pid' => 2912, 'name' => 'spoolsv.exe', 'cpu' => '0.4%', 'memory' => '42 MB'],
            ['pid' => 6420, 'name' => 'svchost.exe', 'cpu' => '0.2%', 'memory' => '95 MB'],
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
                'driver' => 'Windows',
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
                'name' => 'HP LaserJet Pro MFP M428f-M429f',
                'status' => 'Idle',
                'default' => 'Yes',
                'driver' => 'HP Universal Printing PCL 6',
                'port' => 'IP_192.168.1.50',
            ],
            [
                'name' => 'Microsoft Print to PDF',
                'status' => 'Idle',
                'default' => 'No',
                'driver' => 'Microsoft Print To PDF',
                'port' => 'PORTPROMPT:',
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
                'service' => 'Spooler',
                'deleted_files' => 3,
                'driver' => 'Windows',
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
                'interface' => 'Ethernet',
                'ipv4' => '192.168.1.105/24',
                'ipv6' => 'fe80::1a2b:3c4d:5e6f:7a8b',
                'mac' => '00-1A-2B-3C-4D-5E',
                'status' => 'Up',
            ],
            [
                'interface' => 'Wi-Fi',
                'ipv4' => '192.168.1.106/24',
                'ipv6' => 'fe80::9b8a:7f6e:5d4c:3b2a',
                'mac' => '00-1A-2B-3C-4D-5F',
                'status' => 'Up',
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
                'latency' => '1 ms',
            ],
            [
                'target' => '8.8.8.8 (Google Public DNS)',
                'check' => 'ICMP Ping',
                'status' => 'OK',
                'latency' => '14 ms',
            ],
            [
                'target' => '1.1.1.1 (Cloudflare DNS)',
                'check' => 'ICMP Ping',
                'status' => 'OK',
                'latency' => '11 ms',
            ],
            [
                'target' => 'cloudflare.com',
                'check' => 'DNS Resolution',
                'status' => 'OK',
                'latency' => '18 ms',
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
                'commands_executed' => ['ipconfig /flushdns', 'ipconfig /release', 'ipconfig /renew'],
                'driver' => 'Windows',
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
                'pid' => '4512',
                'process' => 'nginx.exe',
            ],
            [
                'protocol' => 'TCP',
                'local_address' => '127.0.0.1',
                'port' => '3306',
                'pid' => '5120',
                'process' => 'mysqld.exe',
            ],
            [
                'protocol' => 'TCP',
                'local_address' => '0.0.0.0',
                'port' => '445',
                'pid' => '4',
                'process' => 'System',
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
                'deleted_files' => 142,
                'skipped_locked_files' => 5,
                'freed_space' => '248.5 MB',
                'paths' => ['%TEMP%', 'C:\\Windows\\Temp'],
                'driver' => 'Windows',
            ],
        );
    }
}
