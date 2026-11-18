<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Contracts\ProcessExecutorInterface;
use App\Contracts\SystemDriverInterface;
use App\DTOs\ActionResult;
use App\DTOs\ProcessResult;
use App\Services\SymfonyProcessExecutor;

class WindowsDriver implements SystemDriverInterface
{
    private const UTF8_PREAMBLE = '[Console]::OutputEncoding = [System.Text.Encoding]::UTF8; $OutputEncoding = [System.Text.Encoding]::UTF8; ';

    public function __construct(
        private readonly ProcessExecutorInterface $processRunner = new SymfonyProcessExecutor(),
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getOsInfo(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$os = Get-CimInstance -ClassName Win32_OperatingSystem;
if ($null -eq $os) { exit 1 }
$uptime = (Get-Date) - $os.LastBootUpTime;
[PSCustomObject]@{
    Caption = [string]$os.Caption;
    Version = [string]$os.Version;
    BuildNumber = [string]$os.BuildNumber;
    OSArchitecture = [string]$os.OSArchitecture;
    CSName = [string]$os.CSName;
    Days = [int]$uptime.Days;
    Hours = [int]$uptime.Hours;
    Minutes = [int]$uptime.Minutes
} | ConvertTo-Json -Compress
PS;

        $data = $this->runPowerShellJson($script);

        if ($data === []) {
            return [
                'os_name' => 'Windows (Unknown)',
                'os_version' => php_uname('r'),
                'kernel' => 'NT',
                'architecture' => php_uname('m'),
                'hostname' => gethostname() ?: 'UNKNOWN',
                'uptime' => 'N/A',
            ];
        }

        $version = trim((string) ($data['Version'] ?? ''));
        $build = trim((string) ($data['BuildNumber'] ?? ''));
        $formattedVersion = $build !== '' ? sprintf('%s (Build %s)', $version, $build) : $version;

        return [
            'os_name' => trim((string) ($data['Caption'] ?? 'Microsoft Windows')),
            'os_version' => $formattedVersion !== '' ? $formattedVersion : 'N/A',
            'kernel' => $version !== '' ? sprintf('NT %s', $version) : 'Windows NT',
            'architecture' => trim((string) ($data['OSArchitecture'] ?? php_uname('m'))),
            'hostname' => trim((string) ($data['CSName'] ?? (gethostname() ?: 'UNKNOWN'))),
            'uptime' => sprintf(
                '%dd %dh %dm',
                (int) ($data['Days'] ?? 0),
                (int) ($data['Hours'] ?? 0),
                (int) ($data['Minutes'] ?? 0)
            ),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getHardwareDiagnostics(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$cpus = @(Get-CimInstance -ClassName Win32_Processor);
$os = Get-CimInstance -ClassName Win32_OperatingSystem;
if ($cpus.Count -eq 0 -and $null -eq $os) { exit 1 }
$firstCpu = $cpus | Select-Object -First 1;
$avgLoad = ($cpus | Measure-Object -Property LoadPercentage -Average).Average;
$cores = ($cpus | Measure-Object -Property NumberOfCores -Sum).Sum;
$threads = ($cpus | Measure-Object -Property NumberOfLogicalProcessors -Sum).Sum;
[PSCustomObject]@{
    CpuName = [string]$firstCpu.Name;
    Cores = [int]$cores;
    Threads = [int]$threads;
    CpuLoad = [double]$avgLoad;
    TotalVisibleMemorySize = [double]$os.TotalVisibleMemorySize;
    FreePhysicalMemory = [double]$os.FreePhysicalMemory
} | ConvertTo-Json -Compress
PS;

        $data = $this->runPowerShellJson($script);

        if ($data === []) {
            return [
                'cpu_model' => 'Unknown Processor',
                'cpu_cores' => 'N/A',
                'cpu_load' => 'N/A',
                'ram_total' => 'N/A',
                'ram_used' => 'N/A',
                'ram_free' => 'N/A',
            ];
        }

        // Win32_OperatingSystem reports memory in Kilobytes (KB).
        $totalBytes = ((float) ($data['TotalVisibleMemorySize'] ?? 0.0)) * 1024.0;
        $freeBytes = ((float) ($data['FreePhysicalMemory'] ?? 0.0)) * 1024.0;
        $usedBytes = max(0.0, $totalBytes - $freeBytes);

        $usedPercent = $totalBytes > 0.0 ? round(($usedBytes / $totalBytes) * 100.0, 1) : 0.0;
        $freePercent = $totalBytes > 0.0 ? round(($freeBytes / $totalBytes) * 100.0, 1) : 0.0;

        return [
            'cpu_model' => trim((string) ($data['CpuName'] ?? 'Unknown CPU')),
            'cpu_cores' => sprintf('%d Cores / %d Logical Processors', (int) ($data['Cores'] ?? 0), (int) ($data['Threads'] ?? 0)),
            'cpu_load' => sprintf('%.1f%%', (float) ($data['CpuLoad'] ?? 0.0)),
            'ram_total' => $this->formatBytes($totalBytes),
            'ram_used' => sprintf('%s (%.1f%%)', $this->formatBytes($usedBytes), $usedPercent),
            'ram_free' => sprintf('%s (%.1f%%)', $this->formatBytes($freeBytes), $freePercent),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getStorageHealth(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
@(Get-CimInstance -ClassName Win32_LogicalDisk -Filter "DriveType=3" | ForEach-Object {
    [PSCustomObject]@{
        DeviceID = [string]$_.DeviceID;
        VolumeName = [string]$_.VolumeName;
        FileSystem = [string]$_.FileSystem;
        Size = [double]$_.Size;
        FreeSpace = [double]$_.FreeSpace
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $disks = [];
        foreach ($items as $disk) {
            if (! is_array($disk)) {
                continue;
            }

            $deviceId = trim((string) ($disk['DeviceID'] ?? 'C:'));
            $fileSystem = trim((string) ($disk['FileSystem'] ?? 'NTFS'));
            $totalBytes = (float) ($disk['Size'] ?? 0.0);
            $freeBytes = (float) ($disk['FreeSpace'] ?? 0.0);
            $usedBytes = max(0.0, $totalBytes - $freeBytes);
            $usagePercent = $totalBytes > 0.0 ? round(($usedBytes / $totalBytes) * 100.0, 1) : 0.0;

            $usageLabel = $usagePercent > 85.0
                ? sprintf('⚠️ %.1f%% [HIGH]', $usagePercent)
                : sprintf('%.1f%%', $usagePercent);

            $disks[] = [
                'filesystem' => $fileSystem !== '' ? sprintf('%s (%s)', $deviceId, $fileSystem) : $deviceId,
                'total' => $this->formatBytes($totalBytes),
                'used' => $this->formatBytes($usedBytes),
                'available' => $this->formatBytes($freeBytes),
                'usage_percent' => $usageLabel,
                'mount' => rtrim($deviceId, '\\') . '\\',
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

        $script = sprintf(
            <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
@(Get-Process | Sort-Object WorkingSet64 -Descending | Select-Object -First %d | ForEach-Object {
    [PSCustomObject]@{
        Id = [int]$_.Id;
        ProcessName = [string]$_.ProcessName;
        WorkingSet64 = [double]$_.WorkingSet64;
        CPU = [double]($_.CPU -as [double])
    }
}) | ConvertTo-Json -Compress
PS,
            $safeLimit
        );

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $processes = [];
        foreach ($items as $proc) {
            if (! is_array($proc)) {
                continue;
            }

            $memoryMb = ((float) ($proc['WorkingSet64'] ?? 0.0)) / 1048576.0;
            $cpuSeconds = (float) ($proc['CPU'] ?? 0.0);

            $processes[] = [
                'pid' => (int) ($proc['Id'] ?? 0),
                'name' => trim((string) ($proc['ProcessName'] ?? 'unknown')),
                'cpu' => sprintf('%.2f s', $cpuSeconds),
                'memory' => sprintf('%.2f MB', $memoryMb),
            ];
        }

        return array_slice($processes, 0, $safeLimit);
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
                details: ['pid' => $pid, 'driver' => 'Windows'],
            );
        }

        $script = sprintf('Stop-Process -Id %d -Force -ErrorAction Stop', $pid);
        $result = $this->runPowerShell($script);

        if ($result->successful) {
            return new ActionResult(
                success: true,
                message: 'messages.system.process_killed_success',
                details: [
                    'pid' => $pid,
                    'driver' => 'Windows',
                ],
            );
        }

        $errorMessage = $result->errorOutput !== '' ? $result->errorOutput : $result->output;
        $isPermissionDenied = $this->isPermissionDeniedError($errorMessage);

        return new ActionResult(
            success: false,
            message: $isPermissionDenied
                ? 'messages.common.permission_denied'
                : 'messages.system.process_killed_failed',
            details: [
                'pid' => $pid,
                'error' => $errorMessage !== '' ? $errorMessage : 'Stop-Process failed',
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getCriticalLogs(int $limit = 15): ActionResult
    {
        $safeLimit = max(1, $limit);

        $script = sprintf(
            <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$events = @(Get-WinEvent -FilterHashtable @{LogName='System','Application'; Level=1,2; StartTime=(Get-Date).AddHours(-24)} -MaxEvents %d -ErrorAction SilentlyContinue | Select-Object TimeCreated, Id, ProviderName, Level, LevelDisplayName, Message | ForEach-Object {
    $timeStr = if ($_.TimeCreated -is [DateTime]) { $_.TimeCreated.ToString('yyyy-MM-dd HH:mm:ss') } else { [string]$_.TimeCreated };
    [PSCustomObject]@{
        TimeCreated = [string]$timeStr;
        Id = [string]$_.Id;
        ProviderName = [string]$_.ProviderName;
        Level = [int]($_.Level -as [int]);
        LevelDisplayName = [string]$_.LevelDisplayName;
        Message = [string](($_.Message -replace '[\r\n\t]+', ' ').Trim())
    }
});
if ($events.Count -eq 0) {
    Write-Output '[]';
} else {
    $events | ConvertTo-Json -Compress
}
PS,
            $safeLimit
        );

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $events = [];
        foreach ($items as $item) {
            if (! is_array($item) || $item === []) {
                continue;
            }

            $levelInt = (int) ($item['Level'] ?? 2);
            $levelDisplay = strtolower(trim((string) ($item['LevelDisplayName'] ?? '')));
            $levelLabel = ($levelInt === 1 || str_contains($levelDisplay, 'crit')) ? 'CRITICAL' : 'ERROR';

            $provider = trim((string) ($item['ProviderName'] ?? 'System'));
            $eventId = trim((string) ($item['Id'] ?? ''));
            $source = $eventId !== '' ? sprintf('%s (ID %s)', $provider, $eventId) : $provider;

            $events[] = [
                'timestamp' => trim((string) ($item['TimeCreated'] ?? '-')) ?: '-',
                'level' => $levelLabel,
                'source' => $source !== '' ? $source : 'System',
                'message' => $this->truncateLogMessage((string) ($item['Message'] ?? '-')),
            ];
        }

        $events = array_slice($events, 0, $safeLimit);

        return new ActionResult(
            success: true,
            message: $events !== [] ? 'messages.logs.fetched_success' : 'messages.logs.no_logs',
            details: [
                'events' => $events,
                'count' => count($events),
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function listPrinters(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
@(Get-Printer | ForEach-Object {
    $jobs = @(Get-PrintJob -PrinterName $_.Name -ErrorAction SilentlyContinue);
    [PSCustomObject]@{
        Name = [string]$_.Name;
        PortName = [string]$_.PortName;
        DriverName = [string]$_.DriverName;
        PrinterStatus = [string]$_.PrinterStatus;
        Default = [bool]$_.Default;
        JobCount = [int]$jobs.Count
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $printers = [];
        foreach ($items as $printer) {
            if (! is_array($printer)) {
                continue;
            }

            $rawStatus = trim((string) ($printer['PrinterStatus'] ?? 'Normal'));
            $jobCount = (int) ($printer['JobCount'] ?? 0);

            $printers[] = [
                'name' => trim((string) ($printer['Name'] ?? 'Unknown Printer')),
                'status' => sprintf('%s (%d jobs)', $this->normalizePrinterStatus($rawStatus), $jobCount),
                'default' => ! empty($printer['Default']) ? 'Yes' : 'No',
                'driver' => trim((string) ($printer['DriverName'] ?? '-')),
                'port' => trim((string) ($printer['PortName'] ?? '-')),
                'jobs' => (string) $jobCount,
            ];
        }

        return $printers;
    }

    /**
     * {@inheritDoc}
     */
    public function clearPrintQueue(): ActionResult
    {
        $script = <<<'PS'
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator);
if (-not $isAdmin) {
    [PSCustomObject]@{
        IsAdmin = $false;
        Success = $false;
        DeletedFiles = 0;
        Error = 'Administrator privileges are required to manage the Print Spooler service.'
    } | ConvertTo-Json -Compress;
    exit 0;
}
try {
    Stop-Service -Name Spooler -Force -ErrorAction Stop;
    $spoolDir = Join-Path $env:SystemRoot 'System32\spool\PRINTERS';
    $deleted = 0;
    if (Test-Path -LiteralPath $spoolDir) {
        $items = @(Get-ChildItem -LiteralPath $spoolDir -Force -ErrorAction SilentlyContinue);
        foreach ($item in $items) {
            Remove-Item -LiteralPath $item.FullName -Force -Recurse -ErrorAction SilentlyContinue;
            if (-not (Test-Path -LiteralPath $item.FullName)) {
                $deleted++;
            }
        }
    }
    Start-Service -Name Spooler -ErrorAction Stop;
    [PSCustomObject]@{
        IsAdmin = $true;
        Success = $true;
        DeletedFiles = [int]$deleted;
        Error = ''
    } | ConvertTo-Json -Compress;
} catch {
    Start-Service -Name Spooler -ErrorAction SilentlyContinue;
    [PSCustomObject]@{
        IsAdmin = $true;
        Success = $false;
        DeletedFiles = 0;
        Error = [string]$_.Exception.Message
    } | ConvertTo-Json -Compress;
}
PS;

        $data = $this->runPowerShellJson($script);

        if ($data === []) {
            return new ActionResult(
                success: false,
                message: 'messages.printer.queue_cleared_failed',
                details: ['service' => 'Spooler', 'driver' => 'Windows'],
            );
        }

        if (($data['IsAdmin'] ?? false) === false) {
            return new ActionResult(
                success: false,
                message: 'messages.common.permission_denied',
                details: [
                    'service' => 'Spooler',
                    'error' => (string) ($data['Error'] ?? 'Access denied'),
                    'driver' => 'Windows',
                ],
            );
        }

        if (($data['Success'] ?? false) === true) {
            return new ActionResult(
                success: true,
                message: 'messages.printer.queue_cleared_success',
                details: [
                    'service' => 'Spooler',
                    'deleted_files' => (int) ($data['DeletedFiles'] ?? 0),
                    'driver' => 'Windows',
                ],
            );
        }

        return new ActionResult(
            success: false,
            message: 'messages.printer.queue_cleared_failed',
            details: [
                'service' => 'Spooler',
                'error' => (string) ($data['Error'] ?? 'Failed to restart Spooler'),
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getNetworkInterfaces(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
@(Get-NetIPConfiguration | ForEach-Object {
    $ipv4 = ($_.IPv4Address | Select-Object -First 1).IPAddress;
    $ipv6 = ($_.IPv6Address | Select-Object -First 1).IPAddress;
    $gw = ($_.IPv4DefaultGateway | Select-Object -First 1).NextHop;
    $dns = @($_.DNSServer | Where-Object { $_.AddressFamily -eq 2 } | Select-Object -ExpandProperty ServerAddresses) -join ', ';
    if ([string]::IsNullOrWhiteSpace($dns)) {
        $dns = @($_.DNSServer.ServerAddresses) -join ', ';
    }
    [PSCustomObject]@{
        InterfaceAlias = [string]$_.InterfaceAlias;
        IPv4 = [string]$ipv4;
        IPv6 = [string]$ipv6;
        Gateway = [string]$gw;
        DnsServers = [string]$dns;
        MacAddress = [string]$_.NetAdapter.MacAddress;
        Status = [string]$_.NetAdapter.Status
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $interfaces = [];
        foreach ($items as $iface) {
            if (! is_array($iface)) {
                continue;
            }

            $interfaces[] = [
                'interface' => trim((string) ($iface['InterfaceAlias'] ?? 'Unknown')),
                'ipv4' => trim((string) ($iface['IPv4'] ?? '')) ?: '-',
                'ipv6' => trim((string) ($iface['IPv6'] ?? '')) ?: '-',
                'gateway' => trim((string) ($iface['Gateway'] ?? '')) ?: '-',
                'dns' => trim((string) ($iface['DnsServers'] ?? '')) ?: '-',
                'mac' => trim((string) ($iface['MacAddress'] ?? '')) ?: '-',
                'status' => trim((string) ($iface['Status'] ?? '')) ?: 'Unknown',
            ];
        }

        return $interfaces;
    }

    /**
     * {@inheritDoc}
     */
    public function runConnectivityHealthCheck(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$results = @();

# 1. Active Network Adapter check
$cfg = Get-NetIPConfiguration | Where-Object { $_.NetAdapter.Status -eq 'Up' -and $null -ne $_.IPv4DefaultGateway } | Select-Object -First 1;
if ($null -eq $cfg) {
    $cfg = Get-NetIPConfiguration | Where-Object { $_.NetAdapter.Status -eq 'Up' } | Select-Object -First 1;
}

if ($null -ne $cfg) {
    $results += [PSCustomObject]@{
        Target = [string]$cfg.InterfaceAlias;
        Check = 'Active Adapter';
        Status = 'OK';
        Latency = '<1 ms'
    };
} else {
    $results += [PSCustomObject]@{
        Target = 'Network Adapter';
        Check = 'Active Adapter';
        Status = 'FAIL';
        Latency = '-'
    };
}

# 2. Ping Default Gateway
$gateway = if ($null -ne $cfg -and $null -ne $cfg.IPv4DefaultGateway) { [string]($cfg.IPv4DefaultGateway | Select-Object -First 1).NextHop } else { '' };
if (-not [string]::IsNullOrWhiteSpace($gateway)) {
    $gwPing = Test-Connection -ComputerName $gateway -Count 1 -ErrorAction SilentlyContinue | Select-Object -First 1;
    $gwLatency = if ($null -ne $gwPing.ResponseTime) { "$($gwPing.ResponseTime) ms" } elseif ($null -ne $gwPing.Latency) { "$($gwPing.Latency) ms" } else { '-' };
    $results += [PSCustomObject]@{
        Target = "$gateway (Gateway)";
        Check = 'ICMP Ping';
        Status = if ($null -ne $gwPing) { 'OK' } else { 'FAIL' };
        Latency = $gwLatency
    };
} else {
    $results += [PSCustomObject]@{
        Target = 'Default Gateway';
        Check = 'ICMP Ping';
        Status = 'FAIL';
        Latency = '-'
    };
}

# 3. Ping External 1.1.1.1
$extPing = Test-Connection -ComputerName '1.1.1.1' -Count 1 -ErrorAction SilentlyContinue | Select-Object -First 1;
$extLatency = if ($null -ne $extPing.ResponseTime) { "$($extPing.ResponseTime) ms" } elseif ($null -ne $extPing.Latency) { "$($extPing.Latency) ms" } else { '-' };
$results += [PSCustomObject]@{
    Target = '1.1.1.1 (Cloudflare)';
    Check = 'External Ping';
    Status = if ($null -ne $extPing) { 'OK' } else { 'FAIL' };
    Latency = $extLatency
};

# 4. DNS Resolution (google.com)
$sw = [System.Diagnostics.Stopwatch]::StartNew();
$dnsResolved = Resolve-DnsName -Name 'google.com' -Type A -ErrorAction SilentlyContinue | Select-Object -First 1;
$sw.Stop();
$results += [PSCustomObject]@{
    Target = 'google.com';
    Check = 'DNS Resolution';
    Status = if ($null -ne $dnsResolved) { 'OK' } else { 'FAIL' };
    Latency = if ($null -ne $dnsResolved) { "$($sw.ElapsedMilliseconds) ms" } else { '-' }
};

$results | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $checks = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $checks[] = [
                'target' => trim((string) ($item['Target'] ?? '-')),
                'check' => trim((string) ($item['Check'] ?? '-')),
                'status' => trim((string) ($item['Status'] ?? 'FAIL')),
                'latency' => trim((string) ($item['Latency'] ?? '-')),
            ];
        }

        return $checks;
    }

    /**
     * {@inheritDoc}
     */
    public function flushDnsAndRenew(): ActionResult
    {
        $result = $this->processRunner->run(['ipconfig', '/flushdns']);
        $cleanOutput = $this->normalizeConsoleOutput($result->output ?: $result->errorOutput);

        if ($result->successful) {
            return new ActionResult(
                success: true,
                message: 'messages.network.flush_success',
                details: [
                    'commands_executed' => ['ipconfig /flushdns'],
                    'output' => $cleanOutput !== '' ? $cleanOutput : 'Successfully flushed the DNS Resolver Cache.',
                    'driver' => 'Windows',
                ],
            );
        }

        return new ActionResult(
            success: false,
            message: 'messages.network.flush_failed',
            details: [
                'commands_executed' => ['ipconfig /flushdns'],
                'error' => $cleanOutput !== '' ? $cleanOutput : 'Failed to flush DNS cache.',
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getListeningPorts(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$procs = @{};
Get-Process | ForEach-Object { $procs[[int]$_.Id] = [string]$_.ProcessName };
@(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Sort-Object LocalPort | ForEach-Object {
    $pidVal = [int]$_.OwningProcess;
    $procName = if ($procs.ContainsKey($pidVal)) { $procs[$pidVal] } else { 'Unknown' };
    [PSCustomObject]@{
        Protocol = 'TCP';
        LocalAddress = [string]$_.LocalAddress;
        LocalPort = [string]$_.LocalPort;
        Pid = [string]$pidVal;
        ProcessName = [string]$procName
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $ports = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ports[] = [
                'protocol' => trim((string) ($item['Protocol'] ?? 'TCP')),
                'local_address' => trim((string) ($item['LocalAddress'] ?? '0.0.0.0')),
                'port' => trim((string) ($item['LocalPort'] ?? '0')),
                'pid' => trim((string) ($item['Pid'] ?? '0')),
                'process' => trim((string) ($item['ProcessName'] ?? 'Unknown')),
            ];
        }

        return $ports;
    }

    /**
     * {@inheritDoc}
     */
    public function cleanTempFiles(): ActionResult
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$targets = @($env:TEMP, 'C:\Windows\Temp') | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -Unique;
$sizeBefore = 0.0;
$sizeAfter = 0.0;
$deletedFiles = 0;
$skippedFiles = 0;

foreach ($dir in $targets) {
    if (Test-Path -LiteralPath $dir) {
        $filesBefore = @(Get-ChildItem -LiteralPath $dir -Recurse -File -Force -ErrorAction SilentlyContinue);
        $sizeBefore += [double](($filesBefore | Measure-Object -Property Length -Sum).Sum);

        $items = @(Get-ChildItem -LiteralPath $dir -Force -ErrorAction SilentlyContinue);
        foreach ($item in $items) {
            Remove-Item -LiteralPath $item.FullName -Recurse -Force -ErrorAction SilentlyContinue;
            if (Test-Path -LiteralPath $item.FullName) {
                $skippedFiles++;
            } else {
                $deletedFiles++;
            }
        }

        $filesAfter = @(Get-ChildItem -LiteralPath $dir -Recurse -File -Force -ErrorAction SilentlyContinue);
        $sizeAfter += [double](($filesAfter | Measure-Object -Property Length -Sum).Sum);
    }
}

$freedBytes = [Math]::Max(0.0, $sizeBefore - $sizeAfter);
[PSCustomObject]@{
    FreedBytes = [double]$freedBytes;
    DeletedFiles = [int]$deletedFiles;
    SkippedFiles = [int]$skippedFiles;
    Targets = @($targets)
} | ConvertTo-Json -Compress
PS;

        $data = $this->runPowerShellJson($script, 60);
        $this->processRunner->run(['ipconfig', '/flushdns']);

        if ($data === []) {
            return new ActionResult(
                success: false,
                message: 'messages.maintenance.clean_failed',
                details: ['driver' => 'Windows'],
            );
        }

        $freedBytes = max(0.0, (float) ($data['FreedBytes'] ?? 0.0));
        $paths = isset($data['Targets']) && is_array($data['Targets'])
            ? array_map('strval', $data['Targets'])
            : ['%TEMP%', 'C:\\Windows\\Temp'];

        return new ActionResult(
            success: true,
            message: 'messages.maintenance.clean_success',
            details: [
                'deleted_files' => (int) ($data['DeletedFiles'] ?? 0),
                'skipped_locked_files' => (int) ($data['SkippedFiles'] ?? 0),
                'freed_space' => $this->formatBytes($freedBytes),
                'paths' => $paths,
                'commands_executed' => ['Remove-Item Temp', 'ipconfig /flushdns'],
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * Execute a PowerShell script safely with UTF-8 output encoding.
     */
    protected function runPowerShell(string $script, int $timeout = 30): ProcessResult
    {
        return $this->processRunner->run([
            'powershell',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            self::UTF8_PREAMBLE . $script,
        ], $timeout);
    }

    /**
     * Execute a PowerShell script and decode its JSON output into an array.
     *
     * @return array<int|string, mixed>
     */
    protected function runPowerShellJson(string $script, int $timeout = 30): array
    {
        $result = $this->runPowerShell($script, $timeout);

        if ($result->output === '') {
            return [];
        }

        $decoded = json_decode($result->output, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalize PowerShell JSON decoded payloads into a list of associative arrays.
     * PowerShell's ConvertTo-Json unwraps single-element arrays into a single JSON object.
     *
     * @param  array<int|string, mixed>  $decoded
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeJsonList(array $decoded): array
    {
        if ($decoded === []) {
            return [];
        }

        if (! array_is_list($decoded)) {
            return [$decoded];
        }

        return $decoded;
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
     * Map Windows PrinterStatus enum numbers or strings into readable labels.
     */
    protected function normalizePrinterStatus(string $status): string
    {
        return match ($status) {
            '0', 'Normal' => 'Normal',
            '1', 'Paused' => 'Paused',
            '2', 'Error' => 'Error',
            '3', 'PendingDeletion' => 'Pending Deletion',
            '4', 'PaperJam' => 'Paper Jam',
            '5', 'PaperOut' => 'Paper Out',
            '6', 'ManualFeed' => 'Manual Feed',
            '7', 'PaperProblem' => 'Paper Problem',
            '8', 'Offline' => 'Offline',
            default => $status !== '' ? $status : 'Normal',
        };
    }

    /**
     * Detect whether a PowerShell/OS error message was caused by insufficient permissions.
     */
    protected function isPermissionDeniedError(string $errorText): bool
    {
        $lower = strtolower($errorText);

        return str_contains($lower, 'access is denied')
            || str_contains($lower, 'acesso negado')
            || str_contains($lower, 'permissiondenied')
            || str_contains($lower, 'unauthorizedaccessexception')
            || str_contains($lower, 'requires elevation');
    }

    /**
     * Normalize multi-line CLI stdout into a compact single-line summary.
     */
    protected function normalizeConsoleOutput(string $output): string
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $output) ?: []),
            fn (string $line): bool => $line !== ''
        ));

        return implode(' | ', $lines);
    }

    /**
     * Normalize whitespace and truncate long event log messages cleanly for terminal tables.
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
