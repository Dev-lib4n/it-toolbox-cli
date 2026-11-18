<?php

declare(strict_types=1);

use App\Contracts\SystemDriverInterface;
use App\DTOs\ActionResult;
use App\Services\HealthCheckService;

/**
 * Helper to build a configurable fake SystemDriverInterface for health check tests.
 *
 * @param  string  $ramUsed
 * @param  array<int, array<string, string>>  $storage
 * @param  array<int, array<string, string>>  $connectivity
 * @param  array<int, array<string, string>>  $printers
 * @param  int  $criticalLogCount
 */
function makeHealthDriver(
    string $ramUsed = '8.00 GB (40.0%)',
    array $storage = [['filesystem' => 'C:', 'total' => '500 GB', 'used' => '200 GB', 'available' => '300 GB', 'usage_percent' => '40.0%', 'mount' => 'C:\\']],
    array $connectivity = [
        ['target' => '1.1.1.1 (Cloudflare)', 'check' => 'External Ping', 'status' => 'OK', 'latency' => '10 ms'],
        ['target' => 'google.com', 'check' => 'DNS Resolution', 'status' => 'OK', 'latency' => '12 ms'],
    ],
    array $printers = [
        ['name' => 'Office_Printer', 'status' => 'Normal (0 jobs)', 'default' => 'Yes', 'driver' => 'PCL6', 'port' => 'IP', 'jobs' => '0'],
    ],
    int $criticalLogCount = 0,
): SystemDriverInterface {
    return new class($ramUsed, $storage, $connectivity, $printers, $criticalLogCount) implements SystemDriverInterface
    {
        /**
         * @param  array<int, array<string, string>>  $storage
         * @param  array<int, array<string, string>>  $connectivity
         * @param  array<int, array<string, string>>  $printers
         */
        public function __construct(
            private readonly string $ramUsed,
            private readonly array $storage,
            private readonly array $connectivity,
            private readonly array $printers,
            private readonly int $criticalLogCount,
        ) {}

        public function getOsInfo(): array
        {
            return ['os_name' => 'Test OS', 'os_version' => '1.0', 'kernel' => '1.0', 'architecture' => 'x64', 'hostname' => 'test-host', 'uptime' => '1d'];
        }

        public function getHardwareDiagnostics(): array
        {
            return ['cpu_model' => 'Test CPU', 'cpu_cores' => '8', 'cpu_load' => '10%', 'ram_total' => '20.00 GB', 'ram_used' => $this->ramUsed, 'ram_free' => '12.00 GB'];
        }

        public function getStorageHealth(): array
        {
            return $this->storage;
        }

        public function getTopProcesses(int $limit = 10): array
        {
            return [];
        }

        public function killProcess(int $pid): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function getCriticalLogs(int $limit = 15): ActionResult
        {
            $events = array_fill(0, $this->criticalLogCount, [
                'timestamp' => '2026-11-18 10:00:00',
                'level' => 'CRITICAL',
                'source' => 'Kernel',
                'message' => 'Simulated critical error',
            ]);

            return new ActionResult(true, 'ok', ['events' => $events, 'count' => count($events)]);
        }

        public function listPrinters(): array
        {
            return $this->printers;
        }

        public function clearPrintQueue(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function getNetworkInterfaces(): array
        {
            return [];
        }

        public function runConnectivityHealthCheck(): array
        {
            return $this->connectivity;
        }

        public function flushDnsAndRenew(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }

        public function getListeningPorts(): array
        {
            return [];
        }

        public function probePort(string $host, int $port, int $timeout = 3): array
        {
            return ['host' => $host, 'port' => $port, 'status' => 'OPEN', 'latency' => '1.00 ms', 'error' => '-'];
        }

        public function getWifiInfo(): ActionResult
        {
            return new ActionResult(false, 'messages.network.no_wifi');
        }

        public function resetNetworkStack(): ActionResult
        {
            return new ActionResult(true, 'messages.network.reset_success');
        }

        public function checkSslCertificate(string $domain, int $port = 443, int $timeout = 5): ActionResult
        {
            return new ActionResult(true, 'messages.network.ssl_valid_success');
        }

        public function scanLanDevices(): array
        {
            return [];
        }

        public function getPublicIpInfo(): ActionResult
        {
            return new ActionResult(true, 'messages.network.public_ip_success');
        }

        public function cleanTempFiles(): ActionResult
        {
            return new ActionResult(true, 'ok');
        }
    };
}

it('calculates a Healthy score (100/100) when all system metrics are optimal', function (): void {
    $service = new HealthCheckService(makeHealthDriver());
    $audit = $service->runAudit();

    expect($audit['score'])->toBe(100)
        ->and($audit['state'])->toBe('healthy')
        ->and($audit['recommendations'])->toBeEmpty();
});

it('calculates a Warning score (50-79) when RAM > 85%, Disk > 85%, and 2 critical logs exist', function (): void {
    // 100 - 15 (RAM 88%) - 15 (Disk 89%) - 5 (2 critical logs) = 65 (Warning)
    $driver = makeHealthDriver(
        ramUsed: '17.60 GB (88.0%)',
        storage: [['filesystem' => 'C:', 'total' => '500 GB', 'used' => '445 GB', 'available' => '55 GB', 'usage_percent' => '⚠️ 89.0% [HIGH]', 'mount' => 'C:\\']],
        criticalLogCount: 2,
    );

    $service = new HealthCheckService($driver);
    $audit = $service->runAudit();

    expect($audit['score'])->toBe(65)
        ->and($audit['state'])->toBe('warning')
        ->and($audit['recommendations'])->toHaveCount(3);
});

it('calculates a Critical score (0-49) and enforces 0 minimum floor when severe failures occur', function (): void {
    // 100 - 25 (RAM 97%) - 25 (Disk 98%) - 25 (No Internet) - 15 (DNS Fail) - 10 (Printer Error) - 15 (8 Critical Logs) = -15 -> clamped to 0
    $driver = makeHealthDriver(
        ramUsed: '19.40 GB (97.0%)',
        storage: [['filesystem' => '/', 'total' => '100 GB', 'used' => '98 GB', 'available' => '2 GB', 'usage_percent' => '⚠️ 98.0% [HIGH]', 'mount' => '/']],
        connectivity: [
            ['target' => '1.1.1.1 (Cloudflare)', 'check' => 'External Ping', 'status' => 'FAIL', 'latency' => '-'],
            ['target' => 'google.com', 'check' => 'DNS Resolution', 'status' => 'FAIL', 'latency' => '-'],
        ],
        printers: [
            ['name' => 'HP_Laser', 'status' => 'Error (3 jobs)', 'default' => 'Yes', 'driver' => 'CUPS', 'port' => 'ipp', 'jobs' => '3'],
        ],
        criticalLogCount: 8,
    );

    $service = new HealthCheckService($driver);
    $audit = $service->runAudit();

    expect($audit['score'])->toBe(0)
        ->and($audit['state'])->toBe('critical')
        ->and($audit['recommendations'])->not->toBeEmpty();
});
