<?php

declare(strict_types=1);

namespace App\Drivers;

use App\Contracts\ProcessExecutorInterface;
use App\Contracts\SystemDriverInterface;
use App\Drivers\Concerns\InteractsWithMaintenanceAndSecurity;
use App\Drivers\Concerns\InteractsWithNetworkSockets;
use App\DTOs\ActionResult;
use App\DTOs\ProcessResult;
use App\Services\SymfonyProcessExecutor;

class WindowsDriver implements SystemDriverInterface
{
    use InteractsWithMaintenanceAndSecurity;
    use InteractsWithNetworkSockets;

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
    public function killProcessByName(string $processName): ActionResult
    {
        $cleanName = preg_replace('/\.exe$/i', '', trim($processName)) ?? '';
        $cleanName = preg_replace('/[^A-Za-z0-9_.-]/', '', $cleanName) ?? '';

        if ($cleanName === '') {
            return new ActionResult(
                success: false,
                message: 'messages.system.process_killed_failed',
                details: ['process_name' => $processName, 'driver' => 'Windows'],
            );
        }

        $script = sprintf("Stop-Process -Name '%s' -Force -ErrorAction Stop", $cleanName);
        $result = $this->runPowerShell($script);

        if ($result->successful) {
            return new ActionResult(
                success: true,
                message: 'messages.system.process_killed_success',
                details: [
                    'process_name' => $cleanName,
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
                'process_name' => $cleanName,
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
    public function getCriticalServices(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$names = @('Spooler','wuauserv','BITS','Dnscache','TermService','AnyDesk','TeamViewer');
@(Get-Service -Name $names -ErrorAction SilentlyContinue | ForEach-Object {
    [PSCustomObject]@{
        Name = [string]$_.Name;
        DisplayName = [string]$_.DisplayName;
        Status = [string]$_.Status;
        StartType = [string]$_.StartType
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $services = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['Name'])) {
                continue;
            }

            $rawStatus = trim((string) ($item['Status'] ?? 'Stopped'));
            $status = match ($rawStatus) {
                '4', 'Running' => 'Running',
                '1', 'Stopped' => 'Stopped',
                default => $rawStatus,
            };

            $services[] = [
                'name' => trim((string) $item['Name']),
                'display_name' => trim((string) ($item['DisplayName'] ?? $item['Name'])),
                'status' => $status,
                'start_type' => trim((string) ($item['StartType'] ?? 'Automatic')),
            ];
        }

        return $services;
    }

    /**
     * {@inheritDoc}
     */
    public function restartService(string $serviceName): ActionResult
    {
        $cleanName = preg_replace('/[^A-Za-z0-9_.-]/', '', trim($serviceName)) ?? '';
        if ($cleanName === '') {
            return new ActionResult(
                success: false,
                message: 'messages.system.service_restarted_failed',
                details: ['service' => $serviceName, 'driver' => 'Windows'],
            );
        }

        $script = sprintf("Restart-Service -Name '%s' -Force -ErrorAction Stop", $cleanName);
        $result = $this->runPowerShell($script);

        if ($result->successful) {
            return new ActionResult(
                success: true,
                message: 'messages.system.service_restarted_success',
                details: [
                    'service' => $cleanName,
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
                : 'messages.system.service_restarted_failed',
            details: [
                'service' => $cleanName,
                'error' => $errorMessage !== '' ? $errorMessage : 'Restart-Service failed',
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getBatteryHealth(): ActionResult
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$bat = Get-CimInstance -ClassName Win32_Battery -ErrorAction SilentlyContinue | Select-Object -First 1;
if ($null -eq $bat) {
    [PSCustomObject]@{ HasBattery = $false } | ConvertTo-Json -Compress;
    exit 0;
}
$static = Get-CimInstance -Namespace 'root\WMI' -ClassName BatteryStaticData -ErrorAction SilentlyContinue | Select-Object -First 1;
$full = Get-CimInstance -Namespace 'root\WMI' -ClassName BatteryFullChargedCapacity -ErrorAction SilentlyContinue | Select-Object -First 1;
$cycle = Get-CimInstance -Namespace 'root\WMI' -ClassName BatteryCycleCount -ErrorAction SilentlyContinue | Select-Object -First 1;
[PSCustomObject]@{
    HasBattery = $true;
    Name = [string]$bat.Name;
    BatteryStatus = [int]$bat.BatteryStatus;
    ChargePercent = [int]$bat.EstimatedChargeRemaining;
    DesignCapacity = [double]($static.DesignedCapacity -as [double]);
    FullChargedCapacity = [double]($full.FullChargedCapacity -as [double]);
    CycleCount = [int]($cycle.CycleCount -as [int])
} | ConvertTo-Json -Compress
PS;

        $data = $this->runPowerShellJson($script);

        if ($data === [] || ($data['HasBattery'] ?? false) === false) {
            return new ActionResult(
                success: false,
                message: 'messages.system.no_battery',
                details: ['driver' => 'Windows'],
            );
        }

        $statusInt = (int) ($data['BatteryStatus'] ?? 1);
        $statusLabel = match ($statusInt) {
            2, 6, 7, 8, 9 => 'Charging / AC Power',
            1, 4, 5 => 'Discharging (On Battery)',
            3 => 'Fully Charged',
            default => 'Unknown',
        };

        $designCap = (float) ($data['DesignCapacity'] ?? 0.0);
        $fullCap = (float) ($data['FullChargedCapacity'] ?? 0.0);
        $healthPercent = ($designCap > 0.0 && $fullCap > 0.0)
            ? min(100.0, round(($fullCap / $designCap) * 100.0, 1))
            : 100.0;
        $wearPercent = max(0.0, round(100.0 - $healthPercent, 1));

        return new ActionResult(
            success: true,
            message: 'messages.system.battery_fetched_success',
            details: [
                'battery_status' => $statusLabel,
                'charge_percent' => sprintf('%d%%', (int) ($data['ChargePercent'] ?? 0)),
                'design_capacity' => $designCap > 0.0 ? sprintf('%.0f mWh', $designCap) : 'N/A',
                'full_charge_capacity' => $fullCap > 0.0 ? sprintf('%.0f mWh', $fullCap) : 'N/A',
                'battery_health' => sprintf('%.1f%% (Wear: %.1f%%)', $healthPercent, $wearPercent),
                'cycle_count' => (int) ($data['CycleCount'] ?? 0) > 0 ? (string) ((int) $data['CycleCount']) : 'N/A',
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getStartupPrograms(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
@(Get-CimInstance -ClassName Win32_StartupCommand -ErrorAction SilentlyContinue | ForEach-Object {
    [PSCustomObject]@{
        Name = [string]$_.Name;
        Command = [string]$_.Command;
        Location = [string]$_.Location;
        User = [string]$_.User
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $entries = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['Name'])) {
                continue;
            }

            $entries[] = [
                'name' => trim((string) $item['Name']),
                'command' => $this->truncateLogMessage((string) ($item['Command'] ?? '-'), 65),
                'location' => trim((string) ($item['Location'] ?? 'Registry')),
                'user' => trim((string) ($item['User'] ?? 'System')),
            ];
        }

        return $entries;
    }

    /**
     * {@inheritDoc}
     */
    public function getUsbDevices(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
@(Get-PnpDevice -Class USB,HIDClass,Printer,Image -PresentOnly -ErrorAction SilentlyContinue | Select-Object -First 40 | ForEach-Object {
    [PSCustomObject]@{
        FriendlyName = [string]$_.FriendlyName;
        Manufacturer = [string]$_.Manufacturer;
        Status = [string]$_.Status;
        InstanceId = [string]$_.InstanceId
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $devices = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['FriendlyName'])) {
                continue;
            }

            $devices[] = [
                'name' => trim((string) $item['FriendlyName']),
                'manufacturer' => trim((string) ($item['Manufacturer'] ?? '-')) ?: '-',
                'status' => trim((string) ($item['Status'] ?? 'OK')) ?: 'OK',
                'device_id' => $this->truncateLogMessage((string) ($item['InstanceId'] ?? '-'), 55),
            ];
        }

        return $devices;
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
    public function getWifiInfo(): ActionResult
    {
        $result = $this->runPowerShell('netsh wlan show interfaces');
        $output = trim($result->output);

        if (! $result->successful || $output === '') {
            return new ActionResult(
                success: false,
                message: 'messages.network.no_wifi',
                details: ['driver' => 'Windows'],
            );
        }

        $lower = strtolower($output);
        if (str_contains($lower, 'no wireless interface')
            || str_contains($lower, 'não há interface sem fio')
            || str_contains($lower, 'is not running')
            || str_contains($lower, 'não está em execução')) {
            return new ActionResult(
                success: false,
                message: 'messages.network.no_wifi',
                details: ['driver' => 'Windows'],
            );
        }

        $fields = [];
        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([^:]+?)\s*:\s*(.+)$/', $line, $matches) === 1) {
                $key = strtolower(trim($matches[1]));
                $val = trim($matches[2]);
                $fields[$key] = $val;
            }
        }

        $ssid = $fields['ssid'] ?? '';
        $interface = $fields['name'] ?? $fields['nome'] ?? 'Wi-Fi';
        $state = $fields['state'] ?? $fields['estado'] ?? 'disconnected';

        if ($ssid === '' && ! isset($fields['name']) && ! isset($fields['nome'])) {
            return new ActionResult(
                success: false,
                message: 'messages.network.no_wifi',
                details: ['driver' => 'Windows'],
            );
        }

        $rxRate = $fields['receive rate (mbps)'] ?? $fields['taxa de recepção (mbps)'] ?? '-';
        $txRate = $fields['transmit rate (mbps)'] ?? $fields['taxa de transmissão (mbps)'] ?? '-';

        return new ActionResult(
            success: true,
            message: 'messages.network.wifi_fetched_success',
            details: [
                'interface' => $interface,
                'state' => $state,
                'ssid' => $ssid !== '' ? $ssid : '-',
                'bssid' => $fields['bssid'] ?? '-',
                'signal' => $fields['signal'] ?? $fields['sinal'] ?? '-',
                'channel' => $fields['channel'] ?? $fields['canal'] ?? '-',
                'band' => $fields['band'] ?? $fields['banda'] ?? $fields['radio type'] ?? $fields['tipo de rádio'] ?? '-',
                'rx_rate' => $rxRate !== '-' && ! str_contains(strtolower($rxRate), 'mbps') ? $rxRate . ' Mbps' : $rxRate,
                'tx_rate' => $txRate !== '-' && ! str_contains(strtolower($txRate), 'mbps') ? $txRate . ' Mbps' : $txRate,
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function resetNetworkStack(): ActionResult
    {
        $adminCheck = $this->processRunner->run(['net', 'session'], 5);
        if (! $adminCheck->successful) {
            return new ActionResult(
                success: false,
                message: 'messages.common.permission_denied',
                details: ['driver' => 'Windows'],
            );
        }

        $steps = [
            ['cmd' => ['ipconfig', '/release'], 'label' => 'ipconfig /release'],
            ['cmd' => ['ipconfig', '/flushdns'], 'label' => 'ipconfig /flushdns'],
            ['cmd' => ['netsh', 'winsock', 'reset'], 'label' => 'netsh winsock reset'],
            ['cmd' => ['netsh', 'int', 'ip', 'reset'], 'label' => 'netsh int ip reset'],
            ['cmd' => ['ipconfig', '/renew'], 'label' => 'ipconfig /renew'],
        ];

        $executed = [];
        foreach ($steps as $step) {
            $this->processRunner->run($step['cmd'], 30);
            $executed[] = $step['label'];
        }

        return new ActionResult(
            success: true,
            message: 'messages.network.reset_success',
            details: [
                'commands_executed' => $executed,
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function scanLanDevices(): array
    {
        $arpResult = $this->processRunner->run(['arp', '-a']);
        if (! $arpResult->successful || $arpResult->output === '') {
            return [];
        }

        $devices = [];
        $currentInterface = 'LAN';
        $lines = preg_split('/\r\n|\r|\n/', $arpResult->output) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^Interface:\s+([0-9.]+)/i', $line, $ifaceMatch) === 1) {
                $currentInterface = $ifaceMatch[1];

                continue;
            }

            if (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\s+([0-9a-fA-F:-]{17})\s+(\S+)/', $line, $entryMatch) === 1) {
                $ip = $entryMatch[1];
                $mac = strtoupper(str_replace('-', ':', $entryMatch[2]));
                $type = ucfirst(strtolower($entryMatch[3]));

                if (! $this->isUnicastLanNeighbor($ip, $mac)) {
                    continue;
                }

                $devices[] = [
                    'ip' => $ip,
                    'mac' => $mac,
                    'status' => $type,
                    'interface' => $currentInterface,
                ];
            }
        }

        return $devices;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>|null  $customTargets
     */
    public function cleanTempFiles(?array $customTargets = null): ActionResult
    {
        if ($customTargets !== null) {
            $targets = array_values(array_unique(array_filter(
                array_map('trim', $customTargets),
                fn (string $path): bool => $path !== ''
            )));
        } else {
            $userTemp = trim((string) (getenv('TEMP') ?: getenv('TMP') ?: ''));
            $candidates = array_filter([$userTemp, 'C:\\Windows\\Temp'], fn (string $path): bool => $path !== '');
            $seenKeys = [];
            $targets = [];
            foreach ($candidates as $candidate) {
                $key = strtolower(rtrim($candidate, '\\/'));
                if (! isset($seenKeys[$key])) {
                    $seenKeys[$key] = true;
                    $targets[] = $candidate;
                }
            }
        }

        $deletedFiles = 0;
        $skippedFiles = 0;
        $freedBytes = 0.0;
        $processedPaths = [];

        foreach ($targets as $dir) {
            if (! @is_dir($dir)) {
                continue;
            }

            if (! @is_readable($dir)) {
                $skippedFiles++;

                continue;
            }

            $processedPaths[] = $dir;
            $stats = $this->purgeDirectorySafely($dir);
            $deletedFiles += $stats['deleted'];
            $skippedFiles += $stats['skipped'];
            $freedBytes += $stats['freed_bytes'];
        }

        $this->processRunner->run(['ipconfig', '/flushdns']);

        return new ActionResult(
            success: true,
            message: 'messages.maintenance.clean_success',
            details: [
                'deleted_files' => $deletedFiles,
                'skipped_locked_files' => $skippedFiles,
                'freed_space' => $this->formatBytes($freedBytes),
                'paths' => $processedPaths !== [] ? $processedPaths : ($targets !== [] ? $targets : ['%TEMP%', 'C:\\Windows\\Temp']),
                'commands_executed' => ['Resilient Temp Cleanup', 'ipconfig /flushdns'],
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function repairOsIntegrity(): ActionResult
    {
        $adminCheck = $this->processRunner->run(['net', 'session'], 5);
        if (! $adminCheck->successful) {
            return new ActionResult(
                success: false,
                message: 'messages.common.permission_denied',
                details: ['driver' => 'Windows'],
            );
        }

        $sfcResult = $this->processRunner->run(['sfc', '/scannow'], 300);
        $dismResult = $this->processRunner->run(['DISM', '/Online', '/Cleanup-Image', '/RestoreHealth'], 300);

        $combinedOutput = $this->normalizeConsoleOutput($sfcResult->output . ' ' . $dismResult->output);
        $succeeded = $sfcResult->successful && $dismResult->successful;

        return new ActionResult(
            success: $succeeded,
            message: $succeeded ? 'messages.maintenance.repair_success' : 'messages.maintenance.repair_failed',
            details: [
                'commands_executed' => [
                    'sfc /scannow',
                    'DISM /Online /Cleanup-Image /RestoreHealth',
                ],
                'output' => $combinedOutput !== '' ? $this->truncateLogMessage($combinedOutput, 110) : 'Completed',
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getPendingUpdates(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$results = @();
try {
    $session = New-Object -ComObject Microsoft.Update.Session;
    $searcher = $session.CreateUpdateSearcher();
    $searchResult = $searcher.Search("IsInstalled=0 and Type='Software'");
    foreach ($upd in $searchResult.Updates) {
        $kb = if ($upd.KBArticleIDs.Count -gt 0) { "KB" + ($upd.KBArticleIDs | Select-Object -First 1) } else { "Update" };
        $results += [PSCustomObject]@{
            Package = [string]$upd.Title;
            Version = [string]$kb;
            Source = 'Windows Update';
            Status = if ($upd.IsDownloaded) { 'Downloaded (Pending Install)' } else { 'Available' }
        };
    }
} catch {}

if ($results.Count -eq 0) {
    $hotfixes = @(Get-HotFix | Sort-Object InstalledOn -Descending | Select-Object -First 5);
    foreach ($hf in $hotfixes) {
        $installedDate = if ($hf.InstalledOn -is [DateTime]) { $hf.InstalledOn.ToString('yyyy-MM-dd') } else { 'Installed' };
        $results += [PSCustomObject]@{
            Package = [string]($hf.Description + ' (' + $hf.HotFixID + ')');
            Version = [string]$hf.HotFixID;
            Source = 'Get-HotFix';
            Status = "Installed ($installedDate)"
        };
    }
}
$results | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script, 45));

        $updates = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['Package'])) {
                continue;
            }

            $updates[] = [
                'package' => $this->truncateLogMessage((string) $item['Package'], 65),
                'version' => trim((string) ($item['Version'] ?? '-')),
                'source' => trim((string) ($item['Source'] ?? 'Windows Update')),
                'status' => trim((string) ($item['Status'] ?? 'Available')),
            ];
        }

        return $updates;
    }

    /**
     * {@inheritDoc}
     */
    public function getScheduledTasks(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
@(Get-ScheduledTask | Where-Object { $_.State -ne 'Disabled' -and $_.TaskPath -notlike '\Microsoft\*' } | Select-Object -First 25 | ForEach-Object {
    $info = Get-ScheduledTaskInfo -TaskName $_.TaskName -TaskPath $_.TaskPath -ErrorAction SilentlyContinue;
    $nextRun = if ($null -ne $info -and $info.NextRunTime -is [DateTime] -and $info.NextRunTime.Year -gt 2000) { $info.NextRunTime.ToString('yyyy-MM-dd HH:mm') } else { '-' };
    [PSCustomObject]@{
        TaskName = [string]$_.TaskName;
        TaskPath = [string]$_.TaskPath;
        NextRun = [string]$nextRun;
        State = [string]$_.State
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $tasks = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['TaskName'])) {
                continue;
            }

            $tasks[] = [
                'name' => trim((string) $item['TaskName']),
                'schedule' => trim((string) ($item['TaskPath'] ?? '\\')),
                'next_run' => trim((string) ($item['NextRun'] ?? '-')),
                'status' => trim((string) ($item['State'] ?? 'Ready')),
            ];
        }

        return $tasks;
    }

    /**
     * {@inheritDoc}
     */
    public function runSecurityAudit(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$fw = @(Get-NetFirewallProfile -ErrorAction SilentlyContinue | ForEach-Object {
    "$($_.Name): " + ($(if ($_.Enabled) { 'ON' } else { 'OFF' }))
});
$fwAllOn = @(Get-NetFirewallProfile -ErrorAction SilentlyContinue | Where-Object { -not $_.Enabled }).Count -eq 0 -and $fw.Count -gt 0;

$mp = Get-MpComputerStatus -ErrorAction SilentlyContinue;
$avEnabled = if ($null -ne $mp) { [bool]$mp.AntivirusEnabled } else { $false };
$rtpEnabled = if ($null -ne $mp) { [bool]$mp.RealTimeProtectionEnabled } else { $false };

$uac = (Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System' -Name 'EnableLUA' -ErrorAction SilentlyContinue).EnableLUA;
$uacEnabled = ($uac -eq 1);

[PSCustomObject]@{
    FirewallAllOn = [bool]$fwAllOn;
    FirewallSummary = [string]($fw -join ', ');
    AvEnabled = [bool]$avEnabled;
    RtpEnabled = [bool]$rtpEnabled;
    UacEnabled = [bool]$uacEnabled
} | ConvertTo-Json -Compress
PS;

        $data = $this->runPowerShellJson($script);

        $fwOn = (bool) ($data['FirewallAllOn'] ?? false);
        $avOn = (bool) ($data['AvEnabled'] ?? false) && (bool) ($data['RtpEnabled'] ?? false);
        $uacOn = (bool) ($data['UacEnabled'] ?? false);

        return [
            [
                'component' => 'Windows Defender Firewall',
                'status' => $fwOn ? 'ENABLED' : 'WARNING',
                'icon' => $fwOn ? '✅' : '⚠️',
                'details' => trim((string) ($data['FirewallSummary'] ?? '')) ?: ($fwOn ? 'All profiles active' : 'One or more profiles disabled'),
            ],
            [
                'component' => 'Microsoft Defender Antivirus',
                'status' => $avOn ? 'PROTECTED' : 'WARNING',
                'icon' => $avOn ? '✅' : '⚠️',
                'details' => sprintf(
                    'AV: %s | Real-Time Protection: %s',
                    ! empty($data['AvEnabled']) ? 'ON' : 'OFF',
                    ! empty($data['RtpEnabled']) ? 'ON' : 'OFF'
                ),
            ],
            [
                'component' => 'User Account Control (UAC)',
                'status' => $uacOn ? 'ENABLED' : 'DISABLED',
                'icon' => $uacOn ? '✅' : '❌',
                'details' => $uacOn ? 'EnableLUA = 1 (Active)' : 'EnableLUA = 0 (Disabled)',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalUsersAudit(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$admins = @(Get-LocalGroupMember -SID 'S-1-5-32-544' -ErrorAction SilentlyContinue | ForEach-Object {
    ($_.Name -split '\\')[-1].ToLower()
});
@(Get-LocalUser -ErrorAction SilentlyContinue | ForEach-Object {
    $isAdm = $admins -contains $_.Name.ToLower();
    $lastLogon = if ($_.LastLogon -is [DateTime] -and $_.LastLogon.Year -gt 2000) { $_.LastLogon.ToString('yyyy-MM-dd HH:mm') } else { 'Never' };
    [PSCustomObject]@{
        Name = [string]$_.Name;
        Enabled = [bool]$_.Enabled;
        IsAdmin = [bool]$isAdm;
        LastLogon = [string]$lastLogon
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $users = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['Name'])) {
                continue;
            }

            $enabled = ! empty($item['Enabled']);
            $isAdmin = ! empty($item['IsAdmin']);

            $users[] = [
                'username' => trim((string) $item['Name']),
                'status' => $enabled ? '✅ Enabled' : '⚪ Disabled',
                'is_admin' => $isAdmin ? '⚠️ YES (Administrator)' : 'No',
                'last_logon' => trim((string) ($item['LastLogon'] ?? '-')),
            ];
        }

        return $users;
    }

    /**
     * {@inheritDoc}
     */
    public function getNetworkShares(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
@(Get-SmbShare -ErrorAction SilentlyContinue | ForEach-Object {
    [PSCustomObject]@{
        Name = [string]$_.Name;
        Path = [string]$_.Path;
        ShareType = [string]$_.ShareType;
        Description = [string]$_.Description
    }
}) | ConvertTo-Json -Compress
PS;

        $items = $this->normalizeJsonList($this->runPowerShellJson($script));

        $shares = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['Name'])) {
                continue;
            }

            $shares[] = [
                'name' => trim((string) $item['Name']),
                'path' => trim((string) ($item['Path'] ?? '-')) ?: '-',
                'type' => 'SMB (' . (trim((string) ($item['ShareType'] ?? 'FileSystemVolume')) ?: 'Disk') . ')',
                'description' => trim((string) ($item['Description'] ?? '-')) ?: '-',
            ];
        }

        return $shares;
    }

    /**
     * {@inheritDoc}
     */
    public function printTestPage(string $printerName): ActionResult
    {
        $cleanName = str_replace("'", "''", trim($printerName));
        if ($cleanName === '') {
            return new ActionResult(
                success: false,
                message: 'messages.printer.test_page_failed',
                details: ['printer' => $printerName, 'driver' => 'Windows'],
            );
        }

        $script = sprintf(
            <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$p = Get-CimInstance -ClassName Win32_Printer | Where-Object { $_.Name -eq '%s' } | Select-Object -First 1;
if ($null -eq $p) {
    [PSCustomObject]@{ Success = $false; ReturnValue = -1; Error = 'Printer not found' } | ConvertTo-Json -Compress;
    exit 0;
}
$res = Invoke-CimMethod -InputObject $p -MethodName PrintTestPage -ErrorAction SilentlyContinue;
$rv = if ($null -ne $res) { [int]$res.ReturnValue } else { -1 };
[PSCustomObject]@{
    Success = ($rv -eq 0);
    ReturnValue = $rv;
    Error = if ($rv -eq 0) { '' } else { "WMI ReturnValue: $rv" }
} | ConvertTo-Json -Compress
PS,
            $cleanName
        );

        $data = $this->runPowerShellJson($script);
        $ok = ($data['Success'] ?? false) === true;

        return new ActionResult(
            success: $ok,
            message: $ok ? 'messages.printer.test_page_success' : 'messages.printer.test_page_failed',
            details: [
                'printer' => trim($printerName),
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function setDefaultPrinter(string $printerName): ActionResult
    {
        $cleanName = str_replace("'", "''", trim($printerName));
        if ($cleanName === '') {
            return new ActionResult(
                success: false,
                message: 'messages.printer.default_set_failed',
                details: ['printer' => $printerName, 'driver' => 'Windows'],
            );
        }

        $script = sprintf(
            <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$p = Get-CimInstance -ClassName Win32_Printer | Where-Object { $_.Name -eq '%s' } | Select-Object -First 1;
if ($null -eq $p) {
    [PSCustomObject]@{ Success = $false; ReturnValue = -1; Error = 'Printer not found' } | ConvertTo-Json -Compress;
    exit 0;
}
$res = Invoke-CimMethod -InputObject $p -MethodName SetDefaultPrinter -ErrorAction SilentlyContinue;
$rv = if ($null -ne $res) { [int]$res.ReturnValue } else { -1 };
[PSCustomObject]@{
    Success = ($rv -eq 0);
    ReturnValue = $rv;
    Error = if ($rv -eq 0) { '' } else { "WMI ReturnValue: $rv" }
} | ConvertTo-Json -Compress
PS,
            $cleanName
        );

        $data = $this->runPowerShellJson($script);
        $ok = ($data['Success'] ?? false) === true;

        return new ActionResult(
            success: $ok,
            message: $ok ? 'messages.printer.default_set_success' : 'messages.printer.default_set_failed',
            details: [
                'printer' => trim($printerName),
                'driver' => 'Windows',
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getHardwareInventory(): array
    {
        $script = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue';
$cs = Get-CimInstance -ClassName Win32_ComputerSystem | Select-Object -First 1;
$bios = Get-CimInstance -ClassName Win32_BIOS | Select-Object -First 1;
$board = Get-CimInstance -ClassName Win32_BaseBoard | Select-Object -First 1;
$os = Get-CimInstance -ClassName Win32_OperatingSystem | Select-Object -First 1;
$cpu = Get-CimInstance -ClassName Win32_Processor | Select-Object -First 1;
$memModules = @(Get-CimInstance -ClassName Win32_PhysicalMemory | ForEach-Object {
    $capGb = [Math]::Round(($_.Capacity / 1GB), 1);
    "$($capGb)GB @ $($_.Speed)MHz"
});
$disks = @(Get-PhysicalDisk -ErrorAction SilentlyContinue | ForEach-Object {
    [PSCustomObject]@{
        Model = [string]$_.FriendlyName;
        Size = [double]$_.Size;
        MediaType = [string]$_.MediaType;
        BusType = [string]$_.BusType
    }
});
if ($disks.Count -eq 0) {
    $disks = @(Get-CimInstance -ClassName Win32_DiskDrive -ErrorAction SilentlyContinue | ForEach-Object {
        [PSCustomObject]@{
            Model = [string]$_.Model;
            Size = [double]$_.Size;
            MediaType = [string]$_.MediaType;
            BusType = [string]$_.InterfaceType
        }
    });
}
[PSCustomObject]@{
    Hostname = [string]$cs.Name;
    Manufacturer = [string]$cs.Manufacturer;
    Model = [string]$cs.Model;
    Domain = [string]$cs.Domain;
    SerialNumber = [string]$bios.SerialNumber;
    BiosVersion = [string]$bios.SMBIOSBIOSVersion;
    Motherboard = [string]("$($board.Manufacturer) $($board.Product)".Trim());
    OsName = [string]$os.Caption;
    OsBuild = [string]("$($os.Version) (Build $($os.BuildNumber))");
    CpuModel = [string]$cpu.Name;
    CpuCores = [string]("$($cpu.NumberOfCores) Cores / $($cpu.NumberOfLogicalProcessors) Threads");
    TotalRamBytes = [double]($cs.TotalPhysicalMemory -as [double]);
    RamModules = [string]($memModules -join ', ');
    Disks = @($disks)
} | ConvertTo-Json -Compress -Depth 4
PS;

        $data = $this->runPowerShellJson($script);
        $interfaces = $this->getNetworkInterfaces();

        $physicalDisks = [];
        foreach ($this->normalizeJsonList((array) ($data['Disks'] ?? [])) as $d) {
            if (! is_array($d)) {
                continue;
            }
            $physicalDisks[] = [
                'model' => trim((string) ($d['Model'] ?? 'Physical Disk')) ?: 'Physical Disk',
                'size' => $this->formatBytes((float) ($d['Size'] ?? 0.0)),
                'media_type' => trim((string) ($d['MediaType'] ?? 'SSD/HDD')) ?: 'SSD/HDD',
                'bus_type' => trim((string) ($d['BusType'] ?? 'SATA/NVMe')) ?: 'SATA/NVMe',
            ];
        }

        return [
            'system_identity' => [
                'hostname' => trim((string) ($data['Hostname'] ?? (gethostname() ?: 'UNKNOWN'))),
                'manufacturer' => trim((string) ($data['Manufacturer'] ?? 'N/A')) ?: 'N/A',
                'model' => trim((string) ($data['Model'] ?? 'N/A')) ?: 'N/A',
                'serial_number' => trim((string) ($data['SerialNumber'] ?? 'N/A')) ?: 'N/A',
                'bios_version' => trim((string) ($data['BiosVersion'] ?? 'N/A')) ?: 'N/A',
                'motherboard' => trim((string) ($data['Motherboard'] ?? 'N/A')) ?: 'N/A',
                'os_edition' => trim((string) ($data['OsName'] ?? 'Windows')) ?: 'Windows',
                'os_build' => trim((string) ($data['OsBuild'] ?? 'N/A')) ?: 'N/A',
            ],
            'processor_memory' => [
                'cpu_model' => trim((string) ($data['CpuModel'] ?? 'Unknown CPU')) ?: 'Unknown CPU',
                'cpu_cores' => trim((string) ($data['CpuCores'] ?? 'N/A')) ?: 'N/A',
                'ram_total' => $this->formatBytes((float) ($data['TotalRamBytes'] ?? 0.0)),
                'ram_modules' => trim((string) ($data['RamModules'] ?? 'N/A')) ?: 'N/A',
            ],
            'physical_disks' => $physicalDisks,
            'network_adapters' => array_map(
                fn (array $iface): array => [
                    'interface' => $iface['interface'] ?? '-',
                    'ipv4' => $iface['ipv4'] ?? '-',
                    'mac' => $iface['mac'] ?? '-',
                    'status' => $iface['status'] ?? '-',
                ],
                $interfaces
            ),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultBrowserCachePaths(): array
    {
        $localAppData = (string) (getenv('LOCALAPPDATA') ?: ((getenv('USERPROFILE') ?: 'C:\\Users\\Default') . '\\AppData\\Local'));

        $paths = [
            'Google Chrome (Cache)' => $localAppData . '\\Google\\Chrome\\User Data\\Default\\Cache\\Cache_Data',
            'Google Chrome (Code Cache)' => $localAppData . '\\Google\\Chrome\\User Data\\Default\\Code Cache',
            'Microsoft Edge (Cache)' => $localAppData . '\\Microsoft\\Edge\\User Data\\Default\\Cache\\Cache_Data',
            'Microsoft Edge (Code Cache)' => $localAppData . '\\Microsoft\\Edge\\User Data\\Default\\Code Cache',
        ];

        $firefoxProfilesDir = $localAppData . '\\Mozilla\\Firefox\\Profiles';
        if (is_dir($firefoxProfilesDir) && is_readable($firefoxProfilesDir)) {
            foreach (glob($firefoxProfilesDir . '\\*\\cache2\\entries') ?: [] as $idx => $ffCache) {
                $paths['Mozilla Firefox (Profile ' . ($idx + 1) . ')'] = $ffCache;
            }
        }

        return $paths;
    }

    /**
     * {@inheritDoc}
     */
    protected function getHostsFilePath(): string
    {
        $systemRoot = (string) (getenv('SystemRoot') ?: 'C:\\Windows');

        return $systemRoot . '\\System32\\drivers\\etc\\hosts';
    }

    /**
     * {@inheritDoc}
     */
    protected function readHostsFileContent(): ?string
    {
        return $this->processRunner->readFile($this->getHostsFilePath());
    }

    /**
     * {@inheritDoc}
     */
    protected function getDriverLabel(): string
    {
        return 'Windows';
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

    /**
     * Windows always uses ';' as the PATH directory separator.
     */
    protected function getEnvPathSeparator(): string
    {
        return ';';
    }
}
