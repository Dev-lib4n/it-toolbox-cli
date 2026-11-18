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
     * Retrieve recent critical and error events from the OS event log / journal (last 24 hours).
     *
     * @param  int  $limit
     * @return ActionResult
     */
    public function getCriticalLogs(int $limit = 15): ActionResult;

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
     * Clean system and user temporary directories safely.
     *
     * @return ActionResult
     */
    public function cleanTempFiles(): ActionResult;
}
