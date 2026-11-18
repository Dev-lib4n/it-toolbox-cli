<?php

declare(strict_types=1);

use App\Contracts\SystemDriverInterface;
use App\DTOs\ActionResult;
use App\Services\ReportExporterService;

it('generates localized Markdown (.md) and JSON (.json) diagnostic reports via ReportExporterService', function (): void {
    $fakeDriver = new class implements SystemDriverInterface
    {
        public function getOsInfo(): array
        {
            return [
                'os_name' => 'Ubuntu 24.04 LTS',
                'os_version' => '24.04',
                'kernel' => '6.8.0-generic',
                'architecture' => 'x86_64',
                'hostname' => 'ci-runner-01',
                'uptime' => '5d 2h 10m',
            ];
        }

        public function getHardwareDiagnostics(): array
        {
            return [
                'cpu_model' => 'AMD EPYC 7763',
                'cpu_cores' => '4 Cores / 8 Threads',
                'cpu_load' => '12.5%',
                'ram_total' => '16.00 GB',
                'ram_used' => '6.40 GB (40.0%)',
                'ram_free' => '9.60 GB (60.0%)',
            ];
        }

        public function getStorageHealth(): array
        {
            return [
                [
                    'filesystem' => '/dev/sda1 (ext4)',
                    'total' => '100.00 GB',
                    'used' => '45.00 GB',
                    'available' => '55.00 GB',
                    'usage_percent' => '45.0%',
                    'mount' => '/',
                ],
            ];
        }

        public function getTopProcesses(int $limit = 10): array
        {
            return [];
        }

        public function killProcess(int $pid): ActionResult
        {
            return new ActionResult(true, 'messages.system.process_killed_success');
        }

        public function getCriticalLogs(int $limit = 15): ActionResult
        {
            return new ActionResult(true, 'messages.logs.no_logs', ['events' => [], 'count' => 0]);
        }

        public function listPrinters(): array
        {
            return [
                [
                    'name' => 'Office_LaserJet',
                    'status' => 'Idle (0 jobs)',
                    'default' => 'Yes',
                    'driver' => 'CUPS',
                    'port' => 'ipp://192.168.1.50/ipp/print',
                    'jobs' => '0',
                ],
            ];
        }

        public function clearPrintQueue(): ActionResult
        {
            return new ActionResult(true, 'messages.printer.queue_cleared_success');
        }

        public function getNetworkInterfaces(): array
        {
            return [
                [
                    'interface' => 'eth0',
                    'ipv4' => '192.168.1.100/24',
                    'gateway' => '192.168.1.1',
                    'dns' => '1.1.1.1, 8.8.8.8',
                    'mac' => '00:1a:2b:3c:4d:5e',
                    'status' => 'UP',
                ],
            ];
        }

        public function runConnectivityHealthCheck(): array
        {
            return [
                [
                    'target' => '1.1.1.1 (Cloudflare)',
                    'check' => 'External Ping',
                    'status' => 'OK',
                    'latency' => '9.5 ms',
                ],
            ];
        }

        public function flushDnsAndRenew(): ActionResult
        {
            return new ActionResult(true, 'messages.network.flush_success');
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
            return new ActionResult(true, 'messages.maintenance.clean_success');
        }
    };

    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_test_' . uniqid();
    mkdir($tempDir, 0777, true);

    try {
        $service = new ReportExporterService($fakeDriver);
        $result = $service->export(
            format: 'both',
            outputDirectory: $tempDir,
            locale: 'pt_BR',
            timestamp: '2026-11-18-100000',
        );

        expect($result->success)->toBeTrue();

        $expectedMdPath = $tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-report-2026-11-18-100000.md';
        $expectedJsonPath = $tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-report-2026-11-18-100000.json';

        expect(file_exists($expectedMdPath))->toBeTrue()
            ->and(file_exists($expectedJsonPath))->toBeTrue();

        $mdContent = (string) file_get_contents($expectedMdPath);
        expect($mdContent)->toContain('Informações do Sistema Operacional')
            ->and($mdContent)->toContain('Ubuntu 24.04 LTS')
            ->and($mdContent)->toContain('Office_LaserJet');

        $jsonDecoded = json_decode((string) file_get_contents($expectedJsonPath), true);
        expect($jsonDecoded)->toBeArray()
            ->and($jsonDecoded['locale'])->toBe('pt_BR')
            ->and($jsonDecoded['os_info']['hostname'])->toBe('ci-runner-01')
            ->and($jsonDecoded['printers'][0]['name'])->toBe('Office_LaserJet');
    } finally {
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-report-2026-11-18-100000.md');
        @unlink($tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-report-2026-11-18-100000.json');
        @rmdir($tempDir);
    }
});
