<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Contracts\ProcessExecutorInterface;
use App\Contracts\SystemDriverInterface;
use App\Drivers\Concerns\InteractsWithNetworkSockets;
use App\DTOs\ActionResult;
use App\Services\SymfonyProcessExecutor;
use Closure;
use FilesystemIterator;
use SplFileInfo;
use Throwable;

class LinuxDriver implements SystemDriverInterface
{
    use InteractsWithNetworkSockets;
    /**
     * Real block/disk filesystem types to include in storage health checks.
     *
     * @var array<int, string>
     */
    private const REAL_FILESYSTEMS = [
        'ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs', 'ntfs', 'ntfs3',
        'fuseblk', 'vfat', 'fat32', 'exfat', 'f2fs', 'jfs', 'reiserfs', 'drvfs',
    ];

    /**
     * Pseudo/virtual filesystems to ignore when parsing df output.
     *
     * @var array<int, string>
     */
    private const IGNORED_FS_PREFIXES = [
        'tmpfs', 'devtmpfs', 'squashfs', 'overlay', 'udev', 'none',
        'efivarfs', 'cgroup', 'proc', 'sysfs', 'shm', 'nsfs', 'tracefs',
    ];

    /**
     * Protected patterns in /tmp and /var/tmp that must never be removed.
     *
     * @var array<int, string>
     */
    private const PROTECTED_TEMP_PATTERNS = [
        '.X11-unix',
        '.XIM-unix',
        '.font-unix',
        '.ICE-unix',
        '.Test-unix',
        'systemd-private-',
        'snap-private-tmp',
        'pulse-',
        'ssh-',
        'tmux-',
    ];

    /**
     * @param  ProcessExecutorInterface  $processRunner
     * @param  Closure|null  $fileReader  Optional custom file reader `fn(string $path): ?string` for testing.
     * @param  Closure|null  $uidResolver  Optional custom UID resolver `fn(): int` for testing.
     * @param  array<int, string>|null  $tempDirectories  Optional custom temp directories for testing.
     */
    public function __construct(
        private readonly ProcessExecutorInterface $processRunner = new SymfonyProcessExecutor(),
        private readonly ?Closure $fileReader = null,
        private readonly ?Closure $uidResolver = null,
        private readonly ?array $tempDirectories = null,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getOsInfo(): array
    {
        $osReleaseRaw = $this->readFile('/etc/os-release') ?? '';
        $osRelease = $this->parseOsRelease($osReleaseRaw);

        $kernelResult = $this->processRunner->run(['uname', '-r']);
        $archResult = $this->processRunner->run(['uname', '-m']);

        $kernel = $kernelResult->successful && $kernelResult->output !== ''
            ? $kernelResult->output
            : php_uname('r');

        $architecture = $archResult->successful && $archResult->output !== ''
            ? $archResult->output
            : php_uname('m');

        $hostnameRaw = $this->readFile('/proc/sys/kernel/hostname');
        $hostname = ($hostnameRaw !== null && trim($hostnameRaw) !== '')
            ? trim($hostnameRaw)
            : (gethostname() ?: 'linux-host');

        $uptimeRaw = $this->readFile('/proc/uptime') ?? '';
        $uptime = $this->parseUptime($uptimeRaw);

        return [
            'os_name' => $osRelease['PRETTY_NAME'] ?? $osRelease['NAME'] ?? 'Linux',
            'os_version' => $osRelease['VERSION'] ?? $osRelease['VERSION_ID'] ?? $kernel,
            'kernel' => $kernel,
            'architecture' => $architecture,
            'hostname' => $hostname,
            'uptime' => $uptime,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getHardwareDiagnostics(): array
    {
        $cpuInfoRaw = $this->readFile('/proc/cpuinfo') ?? '';
        $loadAvgRaw = $this->readFile('/proc/loadavg') ?? '';
        $memInfoRaw = $this->readFile('/proc/meminfo') ?? '';

        $cpuData = $this->parseCpuInfo($cpuInfoRaw);
        $cpuLoad = $this->parseLoadAverage($loadAvgRaw, $cpuData['threads']);
        $memData = $this->parseMemInfo($memInfoRaw);

        return [
            'cpu_model' => $cpuData['model'],
            'cpu_cores' => sprintf('%d Cores / %d Threads', $cpuData['cores'], $cpuData['threads']),
            'cpu_load' => $cpuLoad,
            'ram_total' => $memData['total'],
            'ram_used' => $memData['used'],
            'ram_free' => $memData['free'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getStorageHealth(): array
    {
        $mountsMap = $this->parseProcMounts($this->readFile('/proc/mounts') ?? '');
        $result = $this->processRunner->run(['df', '-P', '-B1']);

        if (! $result->successful || $result->output === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($result->output)) ?: [];
        array_shift($lines);

        $disks = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $columns = preg_split('/\s+/', $line);
            if (! is_array($columns) || count($columns) < 6) {
                continue;
            }

            $device = $columns[0];
            $totalBytes = (float) $columns[1];
            $usedBytes = (float) $columns[2];
            $availableBytes = (float) $columns[3];
            $mountPoint = $columns[5];

            $fsType = $mountsMap[$mountPoint] ?? $mountsMap[$device] ?? null;

            if (! $this->isRealFilesystem($device, $mountPoint, $fsType)) {
                continue;
            }

            $usagePercent = $totalBytes > 0.0
                ? round(($usedBytes / $totalBytes) * 100.0, 1)
                : (float) rtrim($columns[4], '%');

            $usageLabel = $usagePercent > 85.0
                ? sprintf('⚠️ %.1f%% [HIGH]', $usagePercent)
                : sprintf('%.1f%%', $usagePercent);

            $filesystemLabel = $fsType !== null && $fsType !== ''
                ? sprintf('%s (%s)', $device, $fsType)
                : $device;

            $disks[] = [
                'filesystem' => $filesystemLabel,
                'total' => $this->formatBytes($totalBytes),
                'used' => $this->formatBytes($usedBytes),
                'available' => $this->formatBytes($availableBytes),
                'usage_percent' => $usageLabel,
                'mount' => $mountPoint,
            ];
        }

        return $disks;
    }

    /**
     * {@inheritDoc}
     */
    public function getTopProcesses(int $limit = 10): array
    {
        $safeLimit = max(1, $limit);
        $result = $this->processRunner->run(['ps', '-eo', 'pid,comm,%cpu,%mem,rss', '--sort=-rss']);

        if (! $result->successful || $result->output === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($result->output)) ?: [];
        array_shift($lines);

        $processes = [];
        foreach (array_slice($lines, 0, $safeLimit) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s+(.+?)\s+([\d.]+)\s+([\d.]+)\s+(\d+)$/', $line, $matches) === 1) {
                $pid = (int) $matches[1];
                $name = trim($matches[2]);
                $cpuPercent = (float) $matches[3];
                $rssKb = (float) $matches[5];
                $memoryMb = $rssKb / 1024.0;

                $processes[] = [
                    'pid' => $pid,
                    'name' => $name,
                    'cpu' => sprintf('%.1f%%', $cpuPercent),
                    'memory' => sprintf('%.2f MB', $memoryMb),
                ];
            }
        }

        return $processes;
    }

    /**
     * {@inheritDoc}
     */
    public function killProcess(int $pid): ActionResult
    {
        if ($pid <= 0) {
            return new ActionResult(
                success: false,
                message: 'messages.system.process_killed_failed',
                details: ['pid' => $pid, 'driver' => 'Linux'],
            );
        }

        // Primary mechanism on native Linux runtime: posix_kill($pid, SIGTERM)
        if ($this->processRunner instanceof SymfonyProcessExecutor && function_exists('posix_kill')) {
            $sigTerm = defined('SIGTERM') ? (int) constant('SIGTERM') : 15;
            if (@posix_kill($pid, $sigTerm)) {
                return new ActionResult(
                    success: true,
                    message: 'messages.system.process_killed_success',
                    details: [
                        'pid' => $pid,
                        'signal' => 'SIGTERM',
                        'driver' => 'Linux',
                    ],
                );
            }

            if (function_exists('posix_get_last_error') && posix_get_last_error() === 1) {
                return new ActionResult(
                    success: false,
                    message: 'messages.common.permission_denied',
                    details: [
                        'pid' => $pid,
                        'error' => 'Operation not permitted (EPERM)',
                        'driver' => 'Linux',
                    ],
                );
            }
        } else {
            // Polite SIGTERM via process executor when ext-posix is unavailable or mocked
            $sigTermResult = $this->processRunner->run(['kill', '-15', (string) $pid]);

            if ($sigTermResult->successful) {
                return new ActionResult(
                    success: true,
                    message: 'messages.system.process_killed_success',
                    details: [
                        'pid' => $pid,
                        'signal' => 'SIGTERM',
                        'driver' => 'Linux',
                    ],
                );
            }

            $errorOutput = $sigTermResult->errorOutput !== '' ? $sigTermResult->errorOutput : $sigTermResult->output;
            if ($this->isPermissionDeniedError($errorOutput)) {
                return new ActionResult(
                    success: false,
                    message: 'messages.common.permission_denied',
                    details: [
                        'pid' => $pid,
                        'error' => $errorOutput,
                        'driver' => 'Linux',
                    ],
                );
            }
        }

        // Fallback to force kill (kill -9)
        $sigKillResult = $this->processRunner->run(['kill', '-9', (string) $pid]);
        if ($sigKillResult->successful) {
            return new ActionResult(
                success: true,
                message: 'messages.system.process_killed_success',
                details: [
                    'pid' => $pid,
                    'signal' => 'SIGKILL',
                    'driver' => 'Linux',
                ],
            );
        }

        $killError = $sigKillResult->errorOutput !== '' ? $sigKillResult->errorOutput : $sigKillResult->output;

        return new ActionResult(
            success: false,
            message: $this->isPermissionDeniedError($killError)
                ? 'messages.common.permission_denied'
                : 'messages.system.process_killed_failed',
            details: [
                'pid' => $pid,
                'error' => $killError !== '' ? $killError : 'Failed to terminate process',
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function killProcessByName(string $processName): ActionResult
    {
        $cleanName = preg_replace('/[^A-Za-z0-9_.-]/', '', trim($processName)) ?? '';
        if ($cleanName === '') {
            return new ActionResult(
                success: false,
                message: 'messages.system.process_killed_failed',
                details: ['process_name' => $processName, 'driver' => 'Linux'],
            );
        }

        $result = $this->processRunner->run(['pkill', '-15', '-x', $cleanName]);
        if ($result->successful) {
            return new ActionResult(
                success: true,
                message: 'messages.system.process_killed_success',
                details: [
                    'process_name' => $cleanName,
                    'signal' => 'SIGTERM',
                    'driver' => 'Linux',
                ],
            );
        }

        $err = $result->errorOutput !== '' ? $result->errorOutput : $result->output;
        if ($this->isPermissionDeniedError($err)) {
            return new ActionResult(
                success: false,
                message: 'messages.common.permission_denied',
                details: [
                    'process_name' => $cleanName,
                    'error' => $err,
                    'driver' => 'Linux',
                ],
            );
        }

        $forceResult = $this->processRunner->run(['pkill', '-9', '-x', $cleanName]);
        if ($forceResult->successful) {
            return new ActionResult(
                success: true,
                message: 'messages.system.process_killed_success',
                details: [
                    'process_name' => $cleanName,
                    'signal' => 'SIGKILL',
                    'driver' => 'Linux',
                ],
            );
        }

        return new ActionResult(
            success: false,
            message: 'messages.system.process_killed_failed',
            details: [
                'process_name' => $cleanName,
                'error' => $forceResult->errorOutput ?: ($err ?: 'Process not found'),
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getCriticalLogs(int $limit = 15): ActionResult
    {
        $safeLimit = max(1, $limit);
        $events = [];

        $journalResult = $this->processRunner->run([
            'journalctl',
            '-p',
            'err',
            '-n',
            (string) $safeLimit,
            '--since',
            '24 hours ago',
            '--no-pager',
            '-o',
            'json',
        ]);

        if ($journalResult->successful && $journalResult->output !== '') {
            $events = $this->parseJournalctlJsonLogs($journalResult->output, $safeLimit);
        }

        if ($events === []) {
            $syslogRaw = $this->readFile('/var/log/syslog') ?? $this->readFile('/var/log/messages') ?? '';
            if ($syslogRaw !== '') {
                $events = $this->parseSyslogErrors($syslogRaw, $safeLimit);
            }
        }

        return new ActionResult(
            success: true,
            message: $events !== [] ? 'messages.logs.fetched_success' : 'messages.logs.no_logs',
            details: [
                'events' => $events,
                'count' => count($events),
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getCriticalServices(): array
    {
        $candidates = [
            'cups' => 'CUPS Print Spooler',
            'ssh' => 'OpenSSH Server (ssh)',
            'sshd' => 'OpenSSH Daemon (sshd)',
            'docker' => 'Docker Engine',
            'nginx' => 'Nginx Web Server',
            'NetworkManager' => 'NetworkManager',
            'cron' => 'Cron Daemon',
        ];

        $result = $this->processRunner->run([
            'systemctl',
            'show',
            '--property=Id,Description,ActiveState,UnitFileState,LoadState',
            '--',
            'cups.service',
            'ssh.service',
            'sshd.service',
            'docker.service',
            'nginx.service',
            'NetworkManager.service',
            'cron.service',
        ]);

        if (! $result->successful || $result->output === '') {
            return [];
        }

        $services = [];
        $blocks = preg_split('/\r?\n\r?\n/', trim($result->output)) ?: [];

        foreach ($blocks as $block) {
            $props = [];
            foreach (preg_split('/\r\n|\r|\n/', trim($block)) ?: [] as $line) {
                if (str_contains($line, '=')) {
                    [$k, $v] = explode('=', $line, 2);
                    $props[trim($k)] = trim($v);
                }
            }

            $loadState = strtolower($props['LoadState'] ?? '');
            if ($loadState === 'not-found' || empty($props['Id'])) {
                continue;
            }

            $unitName = (string) preg_replace('/\.service$/', '', $props['Id']);
            $activeState = strtolower($props['ActiveState'] ?? 'inactive');
            $status = $activeState === 'active' ? 'Running' : 'Stopped';

            $services[] = [
                'name' => $unitName,
                'display_name' => $props['Description'] ?? ($candidates[$unitName] ?? $unitName),
                'status' => $status,
                'start_type' => $props['UnitFileState'] ?? 'enabled',
            ];
        }

        return $services;
    }

    /**
     * {@inheritDoc}
     */
    public function restartService(string $serviceName): ActionResult
    {
        if (! $this->isRoot()) {
            return new ActionResult(
                success: false,
                message: 'messages.common.permission_denied',
                details: ['service' => $serviceName, 'driver' => 'Linux'],
            );
        }

        $cleanName = preg_replace('/[^A-Za-z0-9_.-]/', '', trim($serviceName)) ?? '';
        if ($cleanName === '') {
            return new ActionResult(
                success: false,
                message: 'messages.system.service_restarted_failed',
                details: ['service' => $serviceName, 'driver' => 'Linux'],
            );
        }

        $result = $this->processRunner->run(['systemctl', 'restart', $cleanName]);
        if ($result->successful) {
            return new ActionResult(
                success: true,
                message: 'messages.system.service_restarted_success',
                details: [
                    'service' => $cleanName,
                    'driver' => 'Linux',
                ],
            );
        }

        $fallback = $this->processRunner->run(['service', $cleanName, 'restart']);
        if ($fallback->successful) {
            return new ActionResult(
                success: true,
                message: 'messages.system.service_restarted_success',
                details: [
                    'service' => $cleanName,
                    'driver' => 'Linux',
                ],
            );
        }

        return new ActionResult(
            success: false,
            message: 'messages.system.service_restarted_failed',
            details: [
                'service' => $cleanName,
                'error' => $result->errorOutput ?: ($fallback->errorOutput ?: 'Failed to restart service'),
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getBatteryHealth(): ActionResult
    {
        $batBase = null;
        foreach (['/sys/class/power_supply/BAT0', '/sys/class/power_supply/BAT1'] as $candidate) {
            if ($this->readFile($candidate . '/status') !== null) {
                $batBase = $candidate;
                break;
            }
        }

        if ($batBase === null) {
            return new ActionResult(
                success: false,
                message: 'messages.system.no_battery',
                details: ['driver' => 'Linux'],
            );
        }

        $status = trim((string) ($this->readFile($batBase . '/status') ?? 'Unknown'));
        $capacity = (int) trim((string) ($this->readFile($batBase . '/capacity') ?? '0'));
        $designRaw = $this->readFile($batBase . '/energy_full_design') ?? $this->readFile($batBase . '/charge_full_design');
        $fullRaw = $this->readFile($batBase . '/energy_full') ?? $this->readFile($batBase . '/charge_full');
        $cyclesRaw = $this->readFile($batBase . '/cycle_count');

        // /sys/class/power_supply reports energy in microWatt-hours (uWh) -> divide by 1000 for mWh
        $designMwh = ($designRaw !== null && is_numeric(trim($designRaw))) ? ((float) trim($designRaw)) / 1000.0 : 0.0;
        $fullMwh = ($fullRaw !== null && is_numeric(trim($fullRaw))) ? ((float) trim($fullRaw)) / 1000.0 : 0.0;
        $cycles = ($cyclesRaw !== null && is_numeric(trim($cyclesRaw))) ? (int) trim($cyclesRaw) : 0;

        $healthPercent = ($designMwh > 0.0 && $fullMwh > 0.0)
            ? min(100.0, round(($fullMwh / $designMwh) * 100.0, 1))
            : 100.0;
        $wearPercent = max(0.0, round(100.0 - $healthPercent, 1));

        return new ActionResult(
            success: true,
            message: 'messages.system.battery_fetched_success',
            details: [
                'battery_status' => $status,
                'charge_percent' => sprintf('%d%%', $capacity),
                'design_capacity' => $designMwh > 0.0 ? sprintf('%.0f mWh', $designMwh) : 'N/A',
                'full_charge_capacity' => $fullMwh > 0.0 ? sprintf('%.0f mWh', $fullMwh) : 'N/A',
                'battery_health' => sprintf('%.1f%% (Wear: %.1f%%)', $healthPercent, $wearPercent),
                'cycle_count' => $cycles > 0 ? (string) $cycles : 'N/A',
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getStartupPrograms(): array
    {
        $result = $this->processRunner->run([
            'systemctl',
            'list-unit-files',
            '--state=enabled',
            '--type=service',
            '--no-pager',
            '--no-legend',
        ]);

        $entries = [];
        if ($result->successful && $result->output !== '') {
            $lines = preg_split('/\r\n|\r|\n/', trim($result->output)) ?: [];
            foreach (array_slice($lines, 0, 30) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (is_array($parts) && count($parts) >= 2) {
                    $unit = $parts[0];
                    $state = $parts[1];
                    $entries[] = [
                        'name' => (string) preg_replace('/\.service$/', '', $unit),
                        'command' => $unit,
                        'location' => 'systemd (' . $state . ')',
                        'user' => 'root',
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * {@inheritDoc}
     */
    public function getUsbDevices(): array
    {
        $result = $this->processRunner->run(['lsusb']);
        if (! $result->successful || $result->output === '') {
            return [];
        }

        $devices = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($result->output)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^Bus\s+(\d+)\s+Device\s+(\d+):\s+ID\s+([0-9a-fA-F]{4}:[0-9a-fA-F]{4})\s*(.*)$/', $line, $m) === 1) {
                $busDev = sprintf('Bus %s Dev %s', $m[1], $m[2]);
                $vendorProd = $m[3];
                $desc = trim($m[4]) !== '' ? trim($m[4]) : 'USB Device';

                $devices[] = [
                    'name' => $desc,
                    'manufacturer' => 'ID ' . $vendorProd,
                    'status' => 'OK',
                    'device_id' => $busDev,
                ];
            }
        }

        return $devices;
    }

    /**
     * {@inheritDoc}
     */
    public function listPrinters(): array
    {
        if (! $this->commandExists('lpstat')) {
            return [];
        }

        $statusResult = $this->processRunner->run(['lpstat', '-p', '-d']);
        if (! $statusResult->successful && $statusResult->output === '') {
            return [];
        }

        $jobsResult = $this->processRunner->run(['lpstat', '-o']);
        $devicesResult = $this->processRunner->run(['lpstat', '-v']);

        $jobCounts = $this->parseCupsJobs($jobsResult->output);
        $deviceUris = $this->parseCupsDevices($devicesResult->output);

        $defaultPrinter = '';
        $lines = preg_split('/\r\n|\r|\n/', $statusResult->output) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/:\s*([A-Za-z0-9_.-]+)$/', trim($line), $matches) === 1
                && (str_contains(strtolower($line), 'default') || str_contains(strtolower($line), 'padrão'))) {
                $defaultPrinter = trim($matches[1]);
            }
        }

        $printers = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^printer\s+(\S+)\s+(.+)$/i', $line, $matches) === 1) {
                $name = $matches[1];
                $rawState = $matches[2];
                $status = str_contains(strtolower($rawState), 'idle')
                    ? 'Idle'
                    : (str_contains(strtolower($rawState), 'printing') ? 'Printing' : (str_contains(strtolower($rawState), 'disabled') ? 'Disabled' : 'Ready'));

                $jobs = $jobCounts[$name] ?? 0;

                $printers[] = [
                    'name' => $name,
                    'status' => sprintf('%s (%d jobs)', $status, $jobs),
                    'default' => $name === $defaultPrinter ? 'Yes' : 'No',
                    'driver' => 'CUPS',
                    'port' => $deviceUris[$name] ?? 'cups://localhost',
                    'jobs' => (string) $jobs,
                ];
            }
        }

        return $printers;
    }

    /**
     * {@inheritDoc}
     */
    public function clearPrintQueue(): ActionResult
    {
        if (! $this->commandExists('cancel')) {
            return new ActionResult(
                success: false,
                message: 'messages.printer.queue_cleared_failed',
                details: [
                    'service' => 'cups',
                    'error' => 'CUPS (cancel command) is not installed on this system.',
                    'driver' => 'Linux',
                ],
            );
        }

        $commandsExecuted = ['cancel -a'];
        $cancelResult = $this->processRunner->run(['cancel', '-a']);

        if (! $cancelResult->successful && $this->isPermissionDeniedError($cancelResult->errorOutput ?: $cancelResult->output)) {
            return new ActionResult(
                success: false,
                message: 'messages.common.permission_denied',
                details: [
                    'service' => 'cups',
                    'error' => $cancelResult->errorOutput ?: $cancelResult->output,
                    'driver' => 'Linux',
                ],
            );
        }

        if ($this->isRoot()) {
            if ($this->commandExists('systemctl')) {
                $restart = $this->processRunner->run(['systemctl', 'restart', 'cups']);
                $commandsExecuted[] = 'systemctl restart cups';
            } elseif ($this->commandExists('service')) {
                $restart = $this->processRunner->run(['service', 'cups', 'restart']);
                $commandsExecuted[] = 'service cups restart';
            } else {
                $restart = $cancelResult;
            }

            if (! $restart->successful && ! $cancelResult->successful) {
                return new ActionResult(
                    success: false,
                    message: 'messages.printer.queue_cleared_failed',
                    details: [
                        'service' => 'cups',
                        'commands_executed' => $commandsExecuted,
                        'error' => $restart->errorOutput ?: 'Failed to restart CUPS daemon',
                        'driver' => 'Linux',
                    ],
                );
            }
        }

        return new ActionResult(
            success: true,
            message: 'messages.printer.queue_cleared_success',
            details: [
                'service' => 'cups',
                'commands_executed' => $commandsExecuted,
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getNetworkInterfaces(): array
    {
        $dnsServers = $this->parseResolvConf($this->readFile('/etc/resolv.conf') ?? '');
        $defaultGateways = $this->getGatewaysByInterface();

        $jsonAddrResult = $this->processRunner->run(['ip', '-j', 'addr']);
        if ($jsonAddrResult->successful && $jsonAddrResult->output !== '') {
            $decoded = json_decode($jsonAddrResult->output, true);
            if (is_array($decoded)) {
                return $this->parseIpJsonInterfaces($decoded, $defaultGateways, $dnsServers);
            }
        }

        $textAddrResult = $this->processRunner->run(['ip', 'addr']);
        if ($textAddrResult->successful && $textAddrResult->output !== '') {
            return $this->parseIpTextInterfaces($textAddrResult->output, $defaultGateways, $dnsServers);
        }

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function runConnectivityHealthCheck(): array
    {
        $checks = [];
        $interfaces = $this->getNetworkInterfaces();

        // 1. Active Interface Check
        $activeIface = null;
        foreach ($interfaces as $iface) {
            if (strtoupper($iface['status']) === 'UP' || ($iface['ipv4'] ?? '-') !== '-') {
                $activeIface = $iface;
                break;
            }
        }

        if ($activeIface !== null) {
            $checks[] = [
                'target' => $activeIface['interface'],
                'check' => 'Interface UP',
                'status' => 'OK',
                'latency' => '<1 ms',
            ];
        } else {
            $checks[] = [
                'target' => 'Network Interface',
                'check' => 'Interface UP',
                'status' => 'FAIL',
                'latency' => '-',
            ];
        }

        // 2. Ping Default Gateway
        $gateway = $activeIface['gateway'] ?? '-';
        if ($gateway === '-' || $gateway === '') {
            $gateways = $this->getGatewaysByInterface();
            $gateway = $gateways['default'] ?? '-';
        }

        if ($gateway !== '-' && $gateway !== '') {
            $gwPing = $this->processRunner->run(['ping', '-c', '1', '-W', '2', $gateway]);
            $checks[] = [
                'target' => sprintf('%s (Gateway)', $gateway),
                'check' => 'ICMP Ping',
                'status' => $gwPing->successful ? 'OK' : 'FAIL',
                'latency' => $gwPing->successful ? $this->extractPingLatency($gwPing->output) : '-',
            ];
        } else {
            $checks[] = [
                'target' => 'Default Gateway',
                'check' => 'ICMP Ping',
                'status' => 'FAIL',
                'latency' => '-',
            ];
        }

        // 3. Ping External 1.1.1.1
        $extPing = $this->processRunner->run(['ping', '-c', '1', '-W', '2', '1.1.1.1']);
        $checks[] = [
            'target' => '1.1.1.1 (Cloudflare)',
            'check' => 'External Ping',
            'status' => $extPing->successful ? 'OK' : 'FAIL',
            'latency' => $extPing->successful ? $this->extractPingLatency($extPing->output) : '-',
        ];

        // 4. DNS Resolution via glibc getent hosts google.com
        $startNs = hrtime(true);
        $dnsResult = $this->processRunner->run(['getent', 'hosts', 'google.com']);
        $elapsedMs = round((hrtime(true) - $startNs) / 1_000_000, 1);

        $checks[] = [
            'target' => 'google.com',
            'check' => 'DNS Resolution (getent)',
            'status' => ($dnsResult->successful && $dnsResult->output !== '') ? 'OK' : 'FAIL',
            'latency' => ($dnsResult->successful && $dnsResult->output !== '') ? sprintf('%.1f ms', $elapsedMs) : '-',
        ];

        return $checks;
    }

    /**
     * {@inheritDoc}
     */
    public function flushDnsAndRenew(): ActionResult
    {
        $candidates = [
            ['binary' => 'resolvectl', 'cmd' => ['resolvectl', 'flush-caches'], 'label' => 'resolvectl flush-caches'],
            ['binary' => 'systemd-resolve', 'cmd' => ['systemd-resolve', '--flush-caches'], 'label' => 'systemd-resolve --flush-caches'],
            ['binary' => 'nscd', 'cmd' => ['nscd', '-i', 'hosts'], 'label' => 'nscd -i hosts'],
        ];

        foreach ($candidates as $candidate) {
            if (! $this->commandExists($candidate['binary'])) {
                continue;
            }

            $result = $this->processRunner->run($candidate['cmd']);
            $output = trim($result->output ?: $result->errorOutput);

            if ($result->successful) {
                return new ActionResult(
                    success: true,
                    message: 'messages.network.flush_success',
                    details: [
                        'commands_executed' => [$candidate['label']],
                        'output' => $output !== '' ? $output : 'DNS cache flushed successfully.',
                        'driver' => 'Linux',
                    ],
                );
            }

            if ($this->isPermissionDeniedError($output)) {
                return new ActionResult(
                    success: false,
                    message: 'messages.common.permission_denied',
                    details: [
                        'commands_executed' => [$candidate['label']],
                        'error' => $output,
                        'driver' => 'Linux',
                    ],
                );
            }
        }

        return new ActionResult(
            success: false,
            message: 'messages.network.flush_failed',
            details: [
                'error' => 'No active DNS caching daemon (resolvectl / nscd) found on this system.',
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getListeningPorts(): array
    {
        if ($this->commandExists('ss')) {
            $result = $this->processRunner->run(['ss', '-tulnp']);
            if ($result->successful && $result->output !== '') {
                return $this->parseSsPorts($result->output);
            }
        }

        if ($this->commandExists('netstat')) {
            $result = $this->processRunner->run(['netstat', '-tulnp']);
            if ($result->successful && $result->output !== '') {
                return $this->parseNetstatPorts($result->output);
            }
        }

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getWifiInfo(): ActionResult
    {
        $nmcliResult = $this->processRunner->run([
            'nmcli',
            '-t',
            '-f',
            'ACTIVE,SSID,BSSID,CHAN,FREQ,RATE,SIGNAL',
            'dev',
            'wifi',
        ]);

        if ($nmcliResult->successful && $nmcliResult->output !== '') {
            $lines = preg_split('/\r\n|\r|\n/', trim($nmcliResult->output)) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                // Split on unescaped colons (nmcli escapes colons inside BSSID as \:)
                $parts = preg_split('/(?<!\\\\):/', $line);
                if (! is_array($parts) || count($parts) < 7) {
                    continue;
                }

                $active = strtolower(trim($parts[0]));
                if ($active !== 'yes' && $active !== 'sim') {
                    continue;
                }

                $ssid = str_replace('\\:', ':', trim($parts[1]));
                $bssid = str_replace('\\:', ':', trim($parts[2]));
                $chan = trim($parts[3]);
                $freq = trim($parts[4]);
                $rate = trim($parts[5]);
                $signal = trim($parts[6]);

                return new ActionResult(
                    success: true,
                    message: 'messages.network.wifi_fetched_success',
                    details: [
                        'interface' => 'wlan0 (nmcli)',
                        'state' => 'Connected',
                        'ssid' => $ssid !== '' ? $ssid : '-',
                        'bssid' => $bssid !== '' ? $bssid : '-',
                        'signal' => $signal !== '' && ! str_ends_with($signal, '%') ? $signal . '%' : ($signal ?: '-'),
                        'channel' => $chan !== '' ? $chan : '-',
                        'band' => $freq !== '' ? $freq : '-',
                        'rx_rate' => $rate !== '' ? $rate : '-',
                        'tx_rate' => $rate !== '' ? $rate : '-',
                        'driver' => 'Linux',
                    ],
                );
            }
        }

        // Fallback to `iw dev`
        $iwResult = $this->processRunner->run(['iw', 'dev']);
        if ($iwResult->successful && $iwResult->output !== '') {
            if (preg_match('/Interface\s+(\S+)/', $iwResult->output, $ifaceMatch) === 1) {
                $iface = $ifaceMatch[1];
                $ssid = preg_match('/ssid\s+(.+)$/m', $iwResult->output, $ssidMatch) === 1 ? trim($ssidMatch[1]) : '-';
                $chan = preg_match('/channel\s+(\d+)\s+\(([^)]+)\)/', $iwResult->output, $chanMatch) === 1
                    ? $chanMatch[1]
                    : '-';
                $freq = isset($chanMatch[2]) ? trim($chanMatch[2]) : '-';

                return new ActionResult(
                    success: true,
                    message: 'messages.network.wifi_fetched_success',
                    details: [
                        'interface' => $iface,
                        'state' => $ssid !== '-' ? 'Connected' : 'Disconnected',
                        'ssid' => $ssid,
                        'bssid' => '-',
                        'signal' => '-',
                        'channel' => $chan,
                        'band' => $freq,
                        'rx_rate' => '-',
                        'tx_rate' => '-',
                        'driver' => 'Linux',
                    ],
                );
            }
        }

        return new ActionResult(
            success: false,
            message: 'messages.network.no_wifi',
            details: ['driver' => 'Linux'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function resetNetworkStack(): ActionResult
    {
        if (! $this->isRoot()) {
            return new ActionResult(
                success: false,
                message: 'messages.common.permission_denied',
                details: ['driver' => 'Linux'],
            );
        }

        $executed = [];

        $nmRestart = $this->processRunner->run(['systemctl', 'restart', 'NetworkManager']);
        if ($nmRestart->successful) {
            $executed[] = 'systemctl restart NetworkManager';
        } else {
            $networkdRestart = $this->processRunner->run(['systemctl', 'restart', 'systemd-networkd']);
            if ($networkdRestart->successful) {
                $executed[] = 'systemctl restart systemd-networkd';
            }
        }

        if ($this->commandExists('dhclient')) {
            $this->processRunner->run(['dhclient', '-r']);
            $this->processRunner->run(['dhclient']);
            $executed[] = 'dhclient -r && dhclient';
        }

        if ($executed === []) {
            return new ActionResult(
                success: false,
                message: 'messages.network.reset_failed',
                details: ['driver' => 'Linux'],
            );
        }

        return new ActionResult(
            success: true,
            message: 'messages.network.reset_success',
            details: [
                'commands_executed' => $executed,
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function scanLanDevices(): array
    {
        $neighResult = $this->processRunner->run(['ip', 'neigh', 'show']);
        if ($neighResult->successful && $neighResult->output !== '') {
            $devices = [];
            $lines = preg_split('/\r\n|\r|\n/', trim($neighResult->output)) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\s+dev\s+(\S+)\s+lladdr\s+([0-9a-fA-F:]{17})\s+(\S+)/', $line, $m) === 1) {
                    $ip = $m[1];
                    $iface = $m[2];
                    $mac = strtoupper($m[3]);
                    $state = strtoupper($m[4]);

                    if (! $this->isUnicastLanNeighbor($ip, $mac)) {
                        continue;
                    }

                    $devices[] = [
                        'ip' => $ip,
                        'mac' => $mac,
                        'status' => $state,
                        'interface' => $iface,
                    ];
                }
            }

            return $devices;
        }

        $arpResult = $this->processRunner->run(['arp', '-n']);
        if ($arpResult->successful && $arpResult->output !== '') {
            $devices = [];
            $lines = preg_split('/\r\n|\r|\n/', trim($arpResult->output)) ?: [];
            foreach ($lines as $line) {
                if (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\s+\S+\s+([0-9a-fA-F:]{17})\s+\S+\s+(\S+)/', trim($line), $m) === 1) {
                    $ip = $m[1];
                    $mac = strtoupper($m[2]);
                    $iface = $m[3];

                    if (! $this->isUnicastLanNeighbor($ip, $mac)) {
                        continue;
                    }

                    $devices[] = [
                        'ip' => $ip,
                        'mac' => $mac,
                        'status' => 'REACHABLE',
                        'interface' => $iface,
                    ];
                }
            }

            return $devices;
        }

        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function cleanTempFiles(): ActionResult
    {
        $isRoot = $this->isRoot();
        $currentUid = $this->getEffectiveUid();
        $cutoffTimestamp = time() - 86400; // 24 hours ago
        $targets = $this->tempDirectories ?? ['/tmp', '/var/tmp'];

        $deletedFiles = 0;
        $skippedFiles = 0;
        $freedBytes = 0.0;
        $commandsExecuted = [];

        foreach ($targets as $dir) {
            if (! is_dir($dir) || ! is_readable($dir)) {
                continue;
            }

            try {
                $iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
                /** @var SplFileInfo $entry */
                foreach ($iterator as $entry) {
                    $filename = $entry->getFilename();

                    if ($this->isProtectedTempEntry($filename)) {
                        $skippedFiles++;

                        continue;
                    }

                    $ownerUid = @$entry->getOwner();
                    $mtime = @$entry->getMTime();

                    if (! $isRoot && $ownerUid !== false && $ownerUid !== $currentUid) {
                        $skippedFiles++;

                        continue;
                    }

                    if ($isRoot && $mtime !== false && $mtime > $cutoffTimestamp) {
                        $skippedFiles++;

                        continue;
                    }

                    $entrySize = $this->calculatePathSize($entry->getPathname());
                    if ($this->deletePathSafely($entry->getPathname())) {
                        $deletedFiles++;
                        $freedBytes += $entrySize;
                    } else {
                        $skippedFiles++;
                    }
                }
            } catch (Throwable) {
                $skippedFiles++;
            }
        }

        if ($isRoot && $this->commandExists('journalctl')) {
            $vacuumResult = $this->processRunner->run(['journalctl', '--vacuum-time=3d']);
            $commandsExecuted[] = 'journalctl --vacuum-time=3d';
            $combinedOutput = $vacuumResult->output . ' ' . $vacuumResult->errorOutput;
            $freedBytes += $this->parseJournalVacuumBytes($combinedOutput);
        }

        return new ActionResult(
            success: true,
            message: 'messages.maintenance.clean_success',
            details: [
                'deleted_files' => $deletedFiles,
                'skipped_locked_files' => $skippedFiles,
                'freed_space' => $this->formatBytes($freedBytes),
                'paths' => $targets,
                'commands_executed' => $commandsExecuted !== [] ? $commandsExecuted : ['Filesystem Temp Cleanup'],
                'driver' => 'Linux',
            ],
        );
    }

    /**
     * Read a file from the filesystem or injected ProcessExecutorInterface / test reader.
     */
    protected function readFile(string $path): ?string
    {
        if ($this->fileReader !== null) {
            return ($this->fileReader)($path);
        }

        if ($this->processRunner instanceof ProcessExecutorInterface) {
            return $this->processRunner->readFile($path);
        }

        if (! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * Check whether a binary exists in the system PATH.
     */
    protected function commandExists(string $binary): bool
    {
        $result = $this->processRunner->run(['which', $binary]);

        return $result->successful && trim($result->output) !== '';
    }

    /**
     * Determine whether the current process runs with root privileges (EUID 0).
     */
    protected function isRoot(): bool
    {
        return $this->getEffectiveUid() === 0;
    }

    /**
     * Get the effective user ID of the running process.
     */
    protected function getEffectiveUid(): int
    {
        if ($this->uidResolver !== null) {
            return (int) ($this->uidResolver)();
        }

        if ($this->processRunner instanceof ProcessExecutorInterface) {
            return $this->processRunner->getEffectiveUid();
        }

        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $result = $this->processRunner->run(['id', '-u']);

        return $result->successful && is_numeric(trim($result->output))
            ? (int) trim($result->output)
            : 1000;
    }

    /**
     * Parse /etc/os-release key-value pairs.
     *
     * @return array<string, string>
     */
    protected function parseOsRelease(string $content): array
    {
        $data = [];
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $data[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }

        return $data;
    }

    /**
     * Format /proc/uptime seconds into "Xd Xh Xm".
     */
    protected function parseUptime(string $raw): string
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        if (! isset($parts[0]) || ! is_numeric($parts[0])) {
            return 'N/A';
        }

        $totalSeconds = (int) floor((float) $parts[0]);
        $days = intdiv($totalSeconds, 86400);
        $hours = intdiv($totalSeconds % 86400, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);

        return sprintf('%dd %dh %dm', $days, $hours, $minutes);
    }

    /**
     * Parse /proc/cpuinfo for processor model, physical cores, and logical threads.
     *
     * @return array{model: string, cores: int, threads: int}
     */
    protected function parseCpuInfo(string $raw): array
    {
        $model = 'Unknown Linux CPU';
        $threads = 0;
        $coresPerSocket = 0;

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $val] = array_map('trim', explode(':', $line, 2));
            $lowerKey = strtolower($key);

            if (($lowerKey === 'model name' || $lowerKey === 'hardware' || $lowerKey === 'model') && $model === 'Unknown Linux CPU' && $val !== '') {
                $model = $val;
            } elseif ($lowerKey === 'processor') {
                $threads++;
            } elseif ($lowerKey === 'cpu cores' && $coresPerSocket === 0 && is_numeric($val)) {
                $coresPerSocket = (int) $val;
            }
        }

        $threads = max(1, $threads);
        $cores = $coresPerSocket > 0 ? $coresPerSocket : $threads;

        return [
            'model' => $model,
            'cores' => $cores,
            'threads' => $threads,
        ];
    }

    /**
     * Parse /proc/loadavg and compute load percentage relative to thread count.
     */
    protected function parseLoadAverage(string $raw, int $threads): string
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        if (! isset($parts[0]) || ! is_numeric($parts[0])) {
            return 'N/A';
        }

        $load1m = (float) $parts[0];
        $safeThreads = max(1, $threads);
        $percentage = min(100.0, round(($load1m / $safeThreads) * 100.0, 1));

        return sprintf('%.1f%% (LoadAvg: %.2f)', $percentage, $load1m);
    }

    /**
     * Parse /proc/meminfo for MemTotal and MemAvailable.
     *
     * @return array{total: string, used: string, free: string}
     */
    protected function parseMemInfo(string $raw): array
    {
        $valuesKb = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z_()]+):\s+(\d+)/', trim($line), $matches) === 1) {
                $valuesKb[$matches[1]] = (float) $matches[2];
            }
        }

        $totalKb = $valuesKb['MemTotal'] ?? 0.0;
        if ($totalKb <= 0.0) {
            return [
                'total' => 'N/A',
                'used' => 'N/A',
                'free' => 'N/A',
            ];
        }

        $availKb = $valuesKb['MemAvailable']
            ?? (($valuesKb['MemFree'] ?? 0.0) + ($valuesKb['Buffers'] ?? 0.0) + ($valuesKb['Cached'] ?? 0.0));

        $totalBytes = $totalKb * 1024.0;
        $availBytes = min($totalBytes, $availKb * 1024.0);
        $usedBytes = max(0.0, $totalBytes - $availBytes);

        $usedPercent = round(($usedBytes / $totalBytes) * 100.0, 1);
        $freePercent = round(($availBytes / $totalBytes) * 100.0, 1);

        return [
            'total' => $this->formatBytes($totalBytes),
            'used' => sprintf('%s (%.1f%%)', $this->formatBytes($usedBytes), $usedPercent),
            'free' => sprintf('%s (%.1f%%)', $this->formatBytes($availBytes), $freePercent),
        ];
    }

    /**
     * Parse /proc/mounts to map device and mountpoint to their filesystem type.
     *
     * @return array<string, string>
     */
    protected function parseProcMounts(string $raw): array
    {
        $map = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (is_array($parts) && count($parts) >= 3) {
                $device = $parts[0];
                $mount = $parts[1];
                $fsType = $parts[2];
                $map[$mount] = $fsType;
                $map[$device] = $fsType;
            }
        }

        return $map;
    }

    /**
     * Determine whether a df row represents a real physical/logical filesystem.
     */
    protected function isRealFilesystem(string $device, string $mountPoint, ?string $fsType): bool
    {
        $lowerDevice = strtolower($device);
        foreach (self::IGNORED_FS_PREFIXES as $prefix) {
            if (str_starts_with($lowerDevice, $prefix)) {
                return false;
            }
        }

        if (str_starts_with($mountPoint, '/snap/') || str_starts_with($mountPoint, '/run/') || str_starts_with($mountPoint, '/sys/') || str_starts_with($mountPoint, '/proc/')) {
            return false;
        }

        if ($fsType !== null && $fsType !== '') {
            return in_array(strtolower($fsType), self::REAL_FILESYSTEMS, true);
        }

        return str_starts_with($device, '/dev/') && ! str_starts_with($device, '/dev/loop');
    }

    /**
     * Parse active print job counts per printer from `lpstat -o`.
     *
     * @return array<string, int>
     */
    protected function parseCupsJobs(string $output): array
    {
        $counts = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/^([A-Za-z0-9_.-]+)-\d+\s+/', $line, $matches) === 1) {
                $printer = $matches[1];
                $counts[$printer] = ($counts[$printer] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Parse printer device URIs from `lpstat -v`.
     *
     * @return array<string, string>
     */
    protected function parseCupsDevices(string $output): array
    {
        $devices = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/for\s+([A-Za-z0-9_.-]+):\s*(.+)$/i', trim($line), $matches) === 1) {
                $devices[trim($matches[1])] = trim($matches[2]);
            }
        }

        return $devices;
    }

    /**
     * Parse nameservers from /etc/resolv.conf.
     */
    protected function parseResolvConf(string $raw): string
    {
        $servers = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^nameserver\s+([^\s#]+)/', $line, $matches) === 1) {
                $servers[] = $matches[1];
            }
        }

        return $servers !== [] ? implode(', ', $servers) : '-';
    }

    /**
     * Retrieve default gateway per interface using `ip -j route` (with text fallback).
     *
     * @return array<string, string>
     */
    protected function getGatewaysByInterface(): array
    {
        $gateways = [];
        $jsonRoute = $this->processRunner->run(['ip', '-j', 'route']);

        if ($jsonRoute->successful && $jsonRoute->output !== '') {
            $decoded = json_decode($jsonRoute->output, true);
            if (is_array($decoded)) {
                foreach ($decoded as $route) {
                    if (is_array($route) && ($route['dst'] ?? '') === 'default' && isset($route['gateway'])) {
                        $gw = (string) $route['gateway'];
                        $dev = (string) ($route['dev'] ?? 'default');
                        $gateways[$dev] = $gw;
                        $gateways['default'] ??= $gw;
                    }
                }

                return $gateways;
            }
        }

        $textRoute = $this->processRunner->run(['ip', 'route']);
        if ($textRoute->successful && $textRoute->output !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $textRoute->output) ?: [];
            foreach ($lines as $line) {
                if (preg_match('/^default\s+via\s+(\S+)(?:\s+dev\s+(\S+))?/', trim($line), $matches) === 1) {
                    $gw = $matches[1];
                    $dev = $matches[2] ?? 'default';
                    $gateways[$dev] = $gw;
                    $gateways['default'] ??= $gw;
                }
            }
        }

        return $gateways;
    }

    /**
     * Parse `ip -j addr` JSON payload into normalized network interface rows.
     *
     * @param  array<int, mixed>  $decoded
     * @param  array<string, string>  $gateways
     * @return array<int, array{interface: string, ipv4: string, ipv6: string, gateway: string, dns: string, mac: string, status: string}>
     */
    protected function parseIpJsonInterfaces(array $decoded, array $gateways, string $dnsServers): array
    {
        $interfaces = [];

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = (string) ($item['ifname'] ?? '');
            if ($name === '' || $name === 'lo') {
                continue;
            }

            $ipv4 = '-';
            $ipv6 = '-';
            foreach ((array) ($item['addr_info'] ?? []) as $addr) {
                if (! is_array($addr)) {
                    continue;
                }

                $local = (string) ($addr['local'] ?? '');
                $prefix = isset($addr['prefixlen']) ? '/' . $addr['prefixlen'] : '';

                if (($addr['family'] ?? '') === 'inet' && $ipv4 === '-' && $local !== '') {
                    $ipv4 = $local . $prefix;
                } elseif (($addr['family'] ?? '') === 'inet6' && $ipv6 === '-' && $local !== '') {
                    $ipv6 = $local . $prefix;
                }
            }

            $interfaces[] = [
                'interface' => $name,
                'ipv4' => $ipv4,
                'ipv6' => $ipv6,
                'gateway' => $gateways[$name] ?? $gateways['default'] ?? '-',
                'dns' => $dnsServers,
                'mac' => (string) ($item['address'] ?? '-'),
                'status' => (string) ($item['operstate'] ?? 'UNKNOWN'),
            ];
        }

        return $interfaces;
    }

    /**
     * Fallback parser for standard `ip addr` text output on older Linux distributions.
     *
     * @param  array<string, string>  $gateways
     * @return array<int, array{interface: string, ipv4: string, ipv6: string, gateway: string, dns: string, mac: string, status: string}>
     */
    protected function parseIpTextInterfaces(string $output, array $gateways, string $dnsServers): array
    {
        $interfaces = [];
        $blocks = preg_split('/^(?=\d+:\s+)/m', trim($output)) ?: [];

        foreach ($blocks as $block) {
            if (preg_match('/^\d+:\s+([^:@\s]+).*?state\s+(\S+)/m', $block, $headerMatch) !== 1) {
                continue;
            }

            $name = trim($headerMatch[1]);
            if ($name === 'lo') {
                continue;
            }

            $status = trim($headerMatch[2]);
            $mac = preg_match('/link\/ether\s+([0-9a-fA-F:]{17})/', $block, $macMatch) === 1 ? $macMatch[1] : '-';
            $ipv4 = preg_match('/inet\s+([0-9.]+\/\d+)/', $block, $v4Match) === 1 ? $v4Match[1] : '-';
            $ipv6 = preg_match('/inet6\s+([0-9a-fA-F:]+\/\d+)/', $block, $v6Match) === 1 ? $v6Match[1] : '-';

            $interfaces[] = [
                'interface' => $name,
                'ipv4' => $ipv4,
                'ipv6' => $ipv6,
                'gateway' => $gateways[$name] ?? $gateways['default'] ?? '-',
                'dns' => $dnsServers,
                'mac' => $mac,
                'status' => $status,
            ];
        }

        return $interfaces;
    }

    /**
     * Extract round-trip time from `ping` output.
     */
    protected function extractPingLatency(string $output): string
    {
        if (preg_match('/time[=<]([\d.]+)\s*ms/i', $output, $matches) === 1) {
            return sprintf('%s ms', $matches[1]);
        }

        return '<1 ms';
    }

    /**
     * Parse `ss -tulnp` output into listening port entries.
     *
     * @return array<int, array{protocol: string, local_address: string, port: string, pid: string, process: string}>
     */
    protected function parseSsPorts(string $output): array
    {
        $ports = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with(strtolower($line), 'netid')) {
                continue;
            }

            $cols = preg_split('/\s+/', $line, 7);
            if (! is_array($cols) || count($cols) < 5) {
                continue;
            }

            $proto = strtoupper($cols[0]);
            $localAddrPort = $cols[4];
            [$address, $port] = $this->splitAddressAndPort($localAddrPort);

            $procField = $cols[6] ?? ($cols[5] ?? '');
            $pid = '-';
            $process = 'Unknown';

            if (preg_match('/users:\(\("([^"]+)",pid=(\d+)/', $procField, $procMatch) === 1) {
                $process = $procMatch[1];
                $pid = $procMatch[2];
            }

            $ports[] = [
                'protocol' => $proto,
                'local_address' => $address,
                'port' => $port,
                'pid' => $pid,
                'process' => $process,
            ];
        }

        return $ports;
    }

    /**
     * Parse `netstat -tulnp` output into listening port entries.
     *
     * @return array<int, array{protocol: string, local_address: string, port: string, pid: string, process: string}>
     */
    protected function parseNetstatPorts(string $output): array
    {
        $ports = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (! preg_match('/^(tcp6?|udp6?)\s+\d+\s+\d+\s+(\S+)\s+\S+\s+(?:LISTEN\s+)?(\S+)?/i', $line, $matches)) {
                continue;
            }

            $proto = strtoupper(rtrim($matches[1], '6'));
            [$address, $port] = $this->splitAddressAndPort($matches[2]);
            $pidProc = $matches[3] ?? '-';

            $pid = '-';
            $process = 'Unknown';
            if (preg_match('/^(\d+)\/(.+)$/', $pidProc, $procMatch) === 1) {
                $pid = $procMatch[1];
                $process = $procMatch[2];
            }

            $ports[] = [
                'protocol' => $proto,
                'local_address' => $address,
                'port' => $port,
                'pid' => $pid,
                'process' => $process,
            ];
        }

        return $ports;
    }

    /**
     * Split a socket `address:port` string (handling IPv6 brackets and interface suffixes).
     *
     * @return array{0: string, 1: string}
     */
    protected function splitAddressAndPort(string $endpoint): array
    {
        $lastColon = strrpos($endpoint, ':');
        if ($lastColon === false) {
            return [$endpoint, '-'];
        }

        $address = substr($endpoint, 0, $lastColon);
        $port = substr($endpoint, $lastColon + 1);

        if (str_contains($address, '%')) {
            $address = explode('%', $address)[0];
        }

        return [$address !== '' ? $address : '*', $port];
    }

    /**
     * Check whether a top-level entry in /tmp or /var/tmp is a protected socket/system directory.
     */
    protected function isProtectedTempEntry(string $filename): bool
    {
        foreach (self::PROTECTED_TEMP_PATTERNS as $pattern) {
            if ($filename === $pattern || str_starts_with($filename, $pattern)) {
                return true;
            }
        }

        return str_ends_with($filename, '.lock')
            || str_ends_with($filename, '.pid')
            || str_ends_with($filename, '.sock');
    }

    /**
     * Calculate byte size of a file or directory tree safely.
     */
    protected function calculatePathSize(string $path): float
    {
        if (is_link($path)) {
            return 0.0;
        }

        if (is_file($path)) {
            $size = @filesize($path);

            return $size !== false ? (float) $size : 0.0;
        }

        if (! is_dir($path) || ! is_readable($path)) {
            return 0.0;
        }

        $total = 0.0;
        try {
            $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                $total += $this->calculatePathSize($item->getPathname());
            }
        } catch (Throwable) {
            // Ignore unreadable subdirectories
        }

        return $total;
    }

    /**
     * Recursively delete a temporary file or directory safely.
     */
    protected function deletePathSafely(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }

        if (! is_dir($path)) {
            return false;
        }

        try {
            $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                $this->deletePathSafely($item->getPathname());
            }
        } catch (Throwable) {
            return false;
        }

        return @rmdir($path);
    }

    /**
     * Parse freed byte count from `journalctl --vacuum-time=3d` output.
     */
    protected function parseJournalVacuumBytes(string $output): float
    {
        if (preg_match('/freed\s+([\d.]+)\s*([KMGTP]?B?)/i', $output, $matches) !== 1) {
            return 0.0;
        }

        $val = (float) $matches[1];
        $unit = strtoupper(rtrim($matches[2], 'B'));

        return match ($unit) {
            'K' => $val * 1024.0,
            'M' => $val * (1024.0 ** 2),
            'G' => $val * (1024.0 ** 3),
            'T' => $val * (1024.0 ** 4),
            default => $val,
        };
    }

    /**
     * Detect whether a Linux CLI error message indicates insufficient permissions.
     */
    protected function isPermissionDeniedError(string $errorText): bool
    {
        $lower = strtolower($errorText);

        return str_contains($lower, 'operation not permitted')
            || str_contains($lower, 'permission denied')
            || str_contains($lower, 'access denied')
            || str_contains($lower, 'authentication is required')
            || str_contains($lower, 'interactive authentication required')
            || str_contains($lower, 'must be root')
            || str_contains($lower, 'permissão negada');
    }

    /**
     * Format byte counts into human-readable units (B, KB, MB, GB, TB).
     */
    protected function formatBytes(float $bytes): string
    {
        if ($bytes <= 0.0) {
            return '0.00 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return sprintf('%.2f %s', $value, $units[$power]);
    }

    /**
     * Parse journalctl JSON or JSON-Lines output into structured critical log entries.
     *
     * @return array<int, array{timestamp: string, level: string, source: string, message: string}>
     */
    protected function parseJournalctlJsonLogs(string $output, int $limit): array
    {
        $trimmed = trim($output);
        if ($trimmed === '' || str_starts_with(strtolower($trimmed), '-- no entries --')) {
            return [];
        }

        $entries = [];
        $decodedWhole = json_decode($trimmed, true);
        if (is_array($decodedWhole)) {
            $entries = array_is_list($decodedWhole) ? $decodedWhole : [$decodedWhole];
        } else {
            $lines = preg_split('/\r\n|\r|\n/', $trimmed) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || ! str_starts_with($line, '{')) {
                    continue;
                }
                $item = json_decode($line, true);
                if (is_array($item)) {
                    $entries[] = $item;
                }
            }
        }

        $events = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $timestamp = '-';
            if (isset($entry['__REALTIME_TIMESTAMP']) && is_numeric($entry['__REALTIME_TIMESTAMP'])) {
                $seconds = (int) floor(((float) $entry['__REALTIME_TIMESTAMP']) / 1_000_000);
                $timestamp = gmdate('Y-m-d H:i:s', $seconds);
            } elseif (isset($entry['SYSLOG_TIMESTAMP'])) {
                $timestamp = trim((string) $entry['SYSLOG_TIMESTAMP']);
            } elseif (isset($entry['timestamp'])) {
                $timestamp = trim((string) $entry['timestamp']);
            }

            $priority = isset($entry['PRIORITY']) ? (int) $entry['PRIORITY'] : 3;
            $level = $priority <= 2 ? 'CRITICAL' : 'ERROR';

            $source = trim((string) ($entry['SYSLOG_IDENTIFIER'] ?? $entry['_COMM'] ?? $entry['_SYSTEMD_UNIT'] ?? 'kernel'));
            $rawMsg = is_array($entry['MESSAGE'] ?? null)
                ? implode(' ', array_map('strval', $entry['MESSAGE']))
                : (string) ($entry['MESSAGE'] ?? '-');

            $events[] = [
                'timestamp' => $timestamp !== '' ? $timestamp : '-',
                'level' => $level,
                'source' => $source !== '' ? $source : 'system',
                'message' => $this->truncateLogMessage($rawMsg),
            ];
        }

        return array_slice($events, 0, $limit);
    }

    /**
     * Fallback parser for /var/log/syslog or /var/log/messages error lines.
     *
     * @return array<int, array{timestamp: string, level: string, source: string, message: string}>
     */
    protected function parseSyslogErrors(string $raw, int $limit): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $matched = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/\b(crit|critical|emerg|alert|fatal|panic|err|error|failed)\b/i', $line) !== 1) {
                continue;
            }

            $level = preg_match('/\b(crit|critical|emerg|alert|fatal|panic)\b/i', $line) === 1
                ? 'CRITICAL'
                : 'ERROR';

            // Match standard syslog: "Nov 18 10:15:02 host service[123]: message" or ISO timestamp
            if (preg_match('/^([A-Z][a-z]{2}\s+\d+\s+\d{2}:\d{2}:\d{2}|\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}\S*)\s+\S+\s+([^:]+):\s*(.+)$/', $line, $m) === 1) {
                $matched[] = [
                    'timestamp' => trim($m[1]),
                    'level' => $level,
                    'source' => trim($m[2]),
                    'message' => $this->truncateLogMessage($m[3]),
                ];
            } else {
                $matched[] = [
                    'timestamp' => '-',
                    'level' => $level,
                    'source' => 'syslog',
                    'message' => $this->truncateLogMessage($line),
                ];
            }
        }

        return array_slice(array_reverse($matched), 0, $limit);
    }

    /**
     * Normalize whitespace and truncate long log messages cleanly for terminal tables.
     */
    protected function truncateLogMessage(string $message, int $maxLength = 90): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', $message));
        if ($clean === '') {
            return '-';
        }

        if (mb_strlen($clean) <= $maxLength) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, $maxLength - 3)) . '...';
    }
}
