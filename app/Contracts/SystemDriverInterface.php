<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ActionResult;

interface SystemDriverInterface
{
    /**
     * Retrieve operating system metadata (name, version, kernel, architecture, hostname, uptime).
     *
     * @return array<string, string>
     */
    public function getOsInfo(): array;

    /**
     * Retrieve CPU and RAM hardware diagnostics.
     *
     * @return array<string, string>
     */
    public function getHardwareDiagnostics(): array;

    /**
     * Retrieve storage volumes/disks usage and health metrics.
     *
     * @return array<int, array{filesystem: string, total: string, used: string, available: string, usage_percent: string, mount: string}>
     */
    public function getStorageHealth(): array;

    /**
     * Retrieve the top resource-consuming processes.
     *
     * @param  int  $limit
     * @return array<int, array{pid: int, name: string, cpu: string, memory: string}>
     */
    public function getTopProcesses(int $limit = 10): array;

    /**
     * Terminate a running process by its PID.
     *
     * @param  int  $pid
     * @return ActionResult
     */
    public function killProcess(int $pid): ActionResult;

    /**
     * Terminate running process(es) by process name.
     *
     * @param  string  $processName
     * @return ActionResult
     */
    public function killProcessByName(string $processName): ActionResult;

    /**
     * Retrieve recent critical and error events from the OS event log / journal (last 24 hours).
     *
     * @param  int  $limit
     * @return ActionResult
     */
    public function getCriticalLogs(int $limit = 15): ActionResult;

    /**
     * Retrieve status of critical IT support system services.
     *
     * @return array<int, array{name: string, display_name: string, status: string, start_type: string}>
     */
    public function getCriticalServices(): array;

    /**
     * Restart a system service by its service identifier.
     *
     * @param  string  $serviceName
     * @return ActionResult
     */
    public function restartService(string $serviceName): ActionResult;

    /**
     * Retrieve battery health, charge level, design vs full capacity, wear percentage, and cycles.
     *
     * @return ActionResult
     */
    public function getBatteryHealth(): ActionResult;

    /**
     * Retrieve programs and services configured to run at system startup / login.
     *
     * @return array<int, array{name: string, command: string, location: string, user: string}>
     */
    public function getStartupPrograms(): array;

    /**
     * Retrieve connected USB and peripheral devices.
     *
     * @return array<int, array{name: string, manufacturer: string, status: string, device_id: string}>
     */
    public function getUsbDevices(): array;

    /**
     * Audit key environment variables and inspect every directory entry in the system PATH.
     *
     * @param  string|null  $customPathString  Optional custom PATH string for testing.
     * @return array{
     *     variables: array<string, string>,
     *     path_entries: array<int, array{index: int, path: string, status: string, icon: string}>,
     *     summary: array{valid: int, broken: int, duplicate: int}
     * }
     */
    public function auditEnvironmentPath(?string $customPathString = null): array;

    /**
     * List installed printers, their current status, and pending print jobs.
     *
     * @return array<int, array{name: string, status: string, default: string, driver: string, port: string, jobs?: string}>
     */
    public function listPrinters(): array;

    /**
     * Restart the print spooler service and purge pending print jobs.
     *
     * @return ActionResult
     */
    public function clearPrintQueue(): ActionResult;

    /**
     * Retrieve active network interfaces, IPv4/IPv6, Gateway, DNS, and MAC addresses.
     *
     * @return array<int, array{interface: string, ipv4: string, ipv6?: string, gateway?: string, dns?: string, mac: string, status: string}>
     */
    public function getNetworkInterfaces(): array;

    /**
     * Run connectivity health checks (adapter, gateway, external ICMP ping, DNS resolution).
     *
     * @return array<int, array{target: string, check: string, status: string, latency: string}>
     */
    public function runConnectivityHealthCheck(): array;

    /**
     * Flush DNS resolver cache and renew DHCP lease where applicable.
     *
     * @return ActionResult
     */
    public function flushDnsAndRenew(): ActionResult;

    /**
     * Retrieve active listening TCP/UDP ports and their owning processes.
     *
     * @return array<int, array{protocol: string, local_address: string, port: string, pid: string, process: string}>
     */
    public function getListeningPorts(): array;

    /**
     * Probe a remote TCP host and port and measure connection latency in milliseconds.
     *
     * @param  string  $host
     * @param  int  $port
     * @param  int  $timeout
     * @return array{host: string, port: int, status: string, latency: string, error: string}
     */
    public function probePort(string $host, int $port, int $timeout = 3): array;

    /**
     * Retrieve wireless (Wi-Fi) adapter connection metrics (SSID, BSSID, Signal, Channel, Band, Rates).
     *
     * @return ActionResult
     */
    public function getWifiInfo(): ActionResult;

    /**
     * Perform a complete network stack reset (Winsock/IP reset on Windows; NetworkManager/DHCP on Linux).
     *
     * @return ActionResult
     */
    public function resetNetworkStack(): ActionResult;

    /**
     * Inspect an HTTPS domain's SSL/TLS certificate (CN, Issuer, Validity dates, Days remaining, SANs).
     *
     * @param  string  $domain
     * @param  int  $port
     * @param  int  $timeout
     * @return ActionResult
     */
    public function checkSslCertificate(string $domain, int $port = 443, int $timeout = 5): ActionResult;

    /**
     * Discover active devices on the local area network (ARP cache + local subnet discovery).
     *
     * @return array<int, array{ip: string, mac: string, status: string, interface: string}>
     */
    public function scanLanDevices(): array;

    /**
     * Retrieve external public IP address, geolocation, ISP/ASN, and reverse hostname.
     *
     * @return ActionResult
     */
    public function getPublicIpInfo(): ActionResult;

    /**
     * Clean system and user temporary directories safely.
     *
     * @return ActionResult
     */
    public function cleanTempFiles(): ActionResult;
}
