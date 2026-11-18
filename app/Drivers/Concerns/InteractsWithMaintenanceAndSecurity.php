<?php

declare(strict_types=1);

namespace App\Drivers\Concerns;

use App\DTOs\ActionResult;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

trait InteractsWithMaintenanceAndSecurity
{
    /**
     * Standard localhost hostnames that are expected in any default hosts file.
     *
     * @var array<int, string>
     */
    private const STANDARD_LOCAL_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'local',
        'ip6-localhost',
        'ip6-loopback',
        'ip6-localnet',
        'ip6-mcastprefix',
        'ip6-allnodes',
        'ip6-allrouters',
        'ip6-allhosts',
        'broadcasthost',
    ];

    /**
     * Ignored virtual/system directory names when scanning for large files.
     *
     * @var array<int, string>
     */
    private const IGNORED_SCAN_DIRS = [
        'proc', 'sys', 'dev', 'run', 'snap', 'winsxs', 'system volume information', '$recycle.bin', '.git', 'node_modules',
    ];

    /**
     * Scan a target directory recursively and return the largest files sorted by size descending.
     *
     * @return array<int, array{path: string, size_bytes: float, size_formatted: string, modified_at: string}>
     */
    public function findLargeFiles(?string $directory = null, int $limit = 15): array
    {
        $safeLimit = max(1, $limit);
        $targetDir = $directory !== null && trim($directory) !== ''
            ? trim($directory)
            : (string) (getenv('USERPROFILE') ?: getenv('HOME') ?: (getcwd() ?: base_path()));

        if (! is_dir($targetDir) || ! is_readable($targetDir)) {
            return [];
        }

        $collected = [];

        try {
            $dirIterator = new RecursiveDirectoryIterator(
                $targetDir,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
            );

            $filterIterator = new \RecursiveCallbackFilterIterator(
                $dirIterator,
                function (SplFileInfo $current): bool {
                    if ($current->isLink()) {
                        return false;
                    }
                    if ($current->isDir()) {
                        $lowerName = strtolower($current->getFilename());

                        return ! in_array($lowerName, self::IGNORED_SCAN_DIRS, true);
                    }

                    return true;
                }
            );

            $iterator = new RecursiveIteratorIterator(
                $filterIterator,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $size = @$file->getSize();
                if ($size === false || $size <= 0) {
                    continue;
                }

                $mtime = @$file->getMTime();
                $collected[] = [
                    'path' => $file->getPathname(),
                    'size_bytes' => (float) $size,
                    'size_formatted' => $this->formatBytes((float) $size),
                    'modified_at' => $mtime !== false ? date('Y-m-d H:i', $mtime) : '-',
                ];
            }
        } catch (Throwable) {
            // Ignore unreadable permission boundaries
        }

        usort(
            $collected,
            fn (array $a, array $b): int => $b['size_bytes'] <=> $a['size_bytes']
        );

        return array_slice($collected, 0, $safeLimit);
    }

    /**
     * Safely clean browser cache directories (Chrome, Edge, Firefox) without touching passwords, cookies, or history.
     *
     * @param  array<string, string>|null  $customCachePaths
     */
    public function cleanBrowserCaches(?array $customCachePaths = null): ActionResult
    {
        $targets = $customCachePaths ?? $this->getDefaultBrowserCachePaths();

        $deletedFiles = 0;
        $skippedFiles = 0;
        $freedBytes = 0.0;
        $cleanedBrowsers = [];

        foreach ($targets as $browserLabel => $cacheDir) {
            if ($cacheDir === '' || ! is_dir($cacheDir) || ! is_readable($cacheDir)) {
                continue;
            }

            $browserFreed = 0.0;
            try {
                $iterator = new FilesystemIterator($cacheDir, FilesystemIterator::SKIP_DOTS);
                /** @var SplFileInfo $entry */
                foreach ($iterator as $entry) {
                    $path = $entry->getPathname();
                    $entrySize = $this->calculateCacheEntrySize($path);

                    if ($this->deleteCacheEntrySafely($path)) {
                        $deletedFiles++;
                        $freedBytes += $entrySize;
                        $browserFreed += $entrySize;
                    } else {
                        $skippedFiles++;
                    }
                }
            } catch (Throwable) {
                $skippedFiles++;
            }

            if ($browserFreed > 0.0 || ! in_array($browserLabel, $cleanedBrowsers, true)) {
                $cleanedBrowsers[] = (string) $browserLabel;
            }
        }

        return new ActionResult(
            success: true,
            message: 'messages.maintenance.browsers_clean_success',
            details: [
                'browsers_cleaned' => $cleanedBrowsers !== [] ? $cleanedBrowsers : ['None Detected'],
                'deleted_files' => $deletedFiles,
                'skipped_locked_files' => $skippedFiles,
                'freed_space' => $this->formatBytes($freedBytes),
                'driver' => $this->getDriverLabel(),
            ],
        );
    }

    /**
     * Inspect the system hosts file and highlight custom or suspicious domain redirections.
     *
     * @return array<int, array{ip: string, host: string, status: string, icon: string}>
     */
    public function inspectHostsFile(?string $customContent = null): array
    {
        $raw = $customContent ?? $this->readHostsFileContent();
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $entries = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $localHostname = strtolower((string) (gethostname() ?: ''));

        foreach ($lines as $line) {
            // Strip inline comments
            if (str_contains($line, '#')) {
                $line = explode('#', $line, 2)[0];
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (! is_array($parts) || count($parts) < 2) {
                continue;
            }

            $ip = array_shift($parts);
            $isLoopbackIp = in_array($ip, ['127.0.0.1', '127.0.1.1', '::1', '0.0.0.0', 'fe00::0', 'ff00::0', 'ff02::1', 'ff02::2', 'ff02::3'], true);

            foreach ($parts as $host) {
                $lowerHost = strtolower(trim($host));
                if ($lowerHost === '') {
                    continue;
                }

                $isStandardHost = in_array($lowerHost, self::STANDARD_LOCAL_HOSTS, true)
                    || ($localHostname !== '' && $lowerHost === $localHostname);

                if ($isLoopbackIp && $isStandardHost) {
                    $status = 'STANDARD';
                    $icon = '✅';
                } elseif ($isLoopbackIp) {
                    $status = 'CUSTOM_LOCAL';
                    $icon = '⚠️';
                } else {
                    $status = 'EXTERNAL_REDIRECT';
                    $icon = '❌';
                }

                $entries[] = [
                    'ip' => $ip,
                    'host' => $host,
                    'status' => $status,
                    'icon' => $icon,
                ];
            }
        }

        return $entries;
    }

    /**
     * Run a fast, zero-dependency CPU single-core and Disk sequential I/O micro-benchmark.
     */
    public function runSystemBenchmark(int $diskSizeMb = 64, float $cpuDurationSeconds = 1.0): ActionResult
    {
        $safeSizeMb = max(1, min(256, $diskSizeMb));
        $safeCpuSeconds = max(0.02, min(5.0, $cpuDurationSeconds));

        // 1. CPU Single-Core Cryptographic SHA-256 Benchmark
        $cpuDurationNs = (int) round($safeCpuSeconds * 1_000_000_000);
        $cpuStartNs = hrtime(true);
        $iterations = 0;
        $seed = 'it-toolbox-cli-v2.0.0-benchmark';

        while ((hrtime(true) - $cpuStartNs) < $cpuDurationNs) {
            for ($i = 0; $i < 2500; $i++) {
                $seed = hash('sha256', $seed . $i);
                $iterations++;
            }
        }

        $cpuElapsedSec = max(0.001, (hrtime(true) - $cpuStartNs) / 1_000_000_000);
        $opsPerSec = (int) round($iterations / $cpuElapsedSec);

        // 2. Disk Sequential Write & Read I/O Benchmark
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_bench_' . uniqid('', true) . '.tmp';
        $chunkSize = 1048576; // 1 MB
        $chunkBuffer = str_repeat( hash('sha512', $seed, true), 16384 ); // 64 bytes * 16384 = 1 MB

        $writeSpeedMb = 0.0;
        $readSpeedMb = 0.0;

        try {
            $fpWrite = @fopen($tempFile, 'wb');
            if (is_resource($fpWrite)) {
                $writeStartNs = hrtime(true);
                for ($m = 0; $m < $safeSizeMb; $m++) {
                    @fwrite($fpWrite, $chunkBuffer, $chunkSize);
                }
                @fflush($fpWrite);
                @fclose($fpWrite);
                $writeElapsedSec = max(0.0001, (hrtime(true) - $writeStartNs) / 1_000_000_000);
                $writeSpeedMb = round($safeSizeMb / $writeElapsedSec, 1);
            }

            if (is_file($tempFile)) {
                $fpRead = @fopen($tempFile, 'rb');
                if (is_resource($fpRead)) {
                    $readStartNs = hrtime(true);
                    while (! feof($fpRead)) {
                        @fread($fpRead, $chunkSize);
                    }
                    @fclose($fpRead);
                    $readElapsedSec = max(0.0001, (hrtime(true) - $readStartNs) / 1_000_000_000);
                    $readSpeedMb = round($safeSizeMb / $readElapsedSec, 1);
                }
            }
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }

        $storageTier = match (true) {
            $writeSpeedMb >= 350.0 => '🚀 NVMe / Fast SSD',
            $writeSpeedMb >= 120.0 => '⚡ SATA SSD',
            default => '🐢 HDD / Standard Storage',
        };

        return new ActionResult(
            success: true,
            message: 'messages.toolbox.benchmark_success',
            details: [
                'cpu_score' => sprintf('%s SHA-256 ops/sec', number_format($opsPerSec)),
                'disk_write_speed' => sprintf('%.1f MB/s (%d MB sample)', $writeSpeedMb, $safeSizeMb),
                'disk_read_speed' => sprintf('%.1f MB/s (%d MB sample)', $readSpeedMb, $safeSizeMb),
                'storage_tier' => $storageTier,
                'driver' => $this->getDriverLabel(),
            ],
        );
    }

    /**
     * Calculate byte size of a cache file or directory tree safely.
     */
    protected function calculateCacheEntrySize(string $path): float
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
                $total += $this->calculateCacheEntrySize($item->getPathname());
            }
        } catch (Throwable) {
            // Ignore unreadable subtrees
        }

        return $total;
    }

    /**
     * Delete a cache file or subdirectory safely.
     */
    protected function deleteCacheEntrySafely(string $path): bool
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
                $this->deleteCacheEntrySafely($item->getPathname());
            }
        } catch (Throwable) {
            return false;
        }

        return @rmdir($path);
    }

    /**
     * Return default browser cache directories for the active OS driver.
     *
     * @return array<string, string>
     */
    abstract protected function getDefaultBrowserCachePaths(): array;

    /**
     * Read the raw content of the system hosts file.
     */
    abstract protected function readHostsFileContent(): ?string;

    /**
     * Return the driver identifier ('Windows' or 'Linux').
     */
    abstract protected function getDriverLabel(): string;
}
