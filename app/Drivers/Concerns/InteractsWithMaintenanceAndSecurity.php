<?php

declare(strict_types=1);

namespace App\Drivers\Concerns;

use App\DTOs\ActionResult;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use SplMinHeap;
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
     * Ignored repository/vendor directory names when scanning for large files.
     *
     * @var array<int, string>
     */
    private const IGNORED_SCAN_DIRS = [
        '.git', 'node_modules',
    ];

    /**
     * Scan a target directory recursively using a fixed-size SplMinHeap and junction/permission guards,
     * returning the top N largest readable files sorted by size descending without buffering all paths in memory.
     *
     * @return array<int, array{path: string, size_bytes: float, size_formatted: string, modified_at: string}>
     */
    public function findLargeFiles(?string $directory = null, int $limit = 15): array
    {
        $safeLimit = max(1, $limit);
        $targetDir = $directory !== null && trim($directory) !== ''
            ? trim($directory)
            : (string) (getenv('USERPROFILE') ?: getenv('HOME') ?: (getcwd() ?: base_path()));

        if ($targetDir === '' || ! is_dir($targetDir) || ! is_readable($targetDir)) {
            return [];
        }

        $heap = new class extends SplMinHeap
        {
            /**
             * Keep the smallest file entry at top() for O(1) threshold checks and O(log N) eviction.
             *
             * @param  array{path: string, size_bytes: float, size_formatted: string, modified_at: string}  $value1
             * @param  array{path: string, size_bytes: float, size_formatted: string, modified_at: string}  $value2
             */
            protected function compare(mixed $value1, mixed $value2): int
            {
                return $value2['size_bytes'] <=> $value1['size_bytes'];
            }
        };

        $this->scanDirectorySafely(
            directory: $targetDir,
            onFile: function (SplFileInfo $file, string $pathname) use ($heap, $safeLimit): void {
                if (! $file->isFile()) {
                    return;
                }

                $rawSize = @$file->getSize();
                if ($rawSize === false || $rawSize <= 0) {
                    return;
                }

                $sizeBytes = (float) $rawSize;
                if ($heap->count() >= $safeLimit) {
                    /** @var array{path: string, size_bytes: float, size_formatted: string, modified_at: string} $smallest */
                    $smallest = $heap->top();
                    if ($sizeBytes <= $smallest['size_bytes']) {
                        return;
                    }
                    $heap->extract();
                }

                $mtime = @$file->getMTime();
                $heap->insert([
                    'path' => $pathname,
                    'size_bytes' => $sizeBytes,
                    'size_formatted' => $this->formatBytes($sizeBytes),
                    'modified_at' => $mtime !== false ? date('Y-m-d H:i', $mtime) : '-',
                ]);
            },
            shouldDescendDir: fn (SplFileInfo $dir): bool => ! in_array(strtolower($dir->getFilename()), self::IGNORED_SCAN_DIRS, true),
        );

        $topFiles = [];
        while (! $heap->isEmpty()) {
            $topFiles[] = $heap->extract();
        }

        usort(
            $topFiles,
            fn (array $a, array $b): int => ($b['size_bytes'] <=> $a['size_bytes']) ?: ($a['path'] <=> $b['path'])
        );

        return $topFiles;
    }

    /**
     * Traverse a directory tree safely without crashing on Windows Junction Points, symlinks, or ACL permission errors.
     *
     * @param  callable(SplFileInfo, string): void  $onFile
     * @param  (callable(SplFileInfo, string): bool)|null  $shouldDescendDir
     * @param  (callable(string): void)|null  $onUnreadableDir
     */
    protected function scanDirectorySafely(
        string $directory,
        callable $onFile,
        ?callable $shouldDescendDir = null,
        ?callable $onUnreadableDir = null,
    ): void {
        if ($directory === '' || ! @is_dir($directory) || ! @is_readable($directory)) {
            return;
        }

        // Canonicalize the scan root (expanding Windows 8.3 short paths like RUNNER~1 to runneradmin)
        // so RecursiveDirectoryIterator items always share the exact same prefix as the root.
        $canonicalRoot = realpath($directory) ?: $directory;
        $canonicalRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $canonicalRoot);
        if (preg_match('/^[A-Za-z]:$/', $canonicalRoot) === 1) {
            $canonicalRoot .= DIRECTORY_SEPARATOR;
        } elseif ($canonicalRoot !== DIRECTORY_SEPARATOR && preg_match('/^[A-Za-z]:\\\\$/', $canonicalRoot) !== 1) {
            $canonicalRoot = rtrim($canonicalRoot, '/\\');
        }

        try {
            $dirIterator = new RecursiveDirectoryIterator(
                $canonicalRoot,
                FilesystemIterator::SKIP_DOTS
            );

            $filterIterator = new RecursiveCallbackFilterIterator(
                $dirIterator,
                function (SplFileInfo $current) use ($canonicalRoot, $shouldDescendDir, $onUnreadableDir): bool {
                    try {
                        if ($current->isLink()) {
                            return false;
                        }

                        $pathname = $current->getPathname();
                        if ($this->isExcludedDirectory($pathname, $canonicalRoot)) {
                            return false;
                        }

                        if ($current->isDir()) {
                            if (! @is_readable($pathname)) {
                                if ($onUnreadableDir !== null) {
                                    $onUnreadableDir($pathname);
                                }

                                return false;
                            }

                            if ($shouldDescendDir !== null && ! $shouldDescendDir($current, $pathname)) {
                                return false;
                            }
                        }

                        return true;
                    } catch (Throwable) {
                        return false;
                    }
                }
            );

            $iterator = new RecursiveIteratorIterator(
                $filterIterator,
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                try {
                    if ($item->isLink()) {
                        continue;
                    }

                    $onFile($item, $item->getPathname());
                } catch (Throwable) {
                    continue;
                }
            }
        } catch (Throwable) {
            // Gracefully tolerate top-level permission or junction boundaries
        }
    }

    /**
     * Delete all accessible files and empty subdirectories within a target folder safely,
     * counting only bytes actually freed and skipping locked entries without aborting.
     *
     * @return array{deleted: int, skipped: int, freed_bytes: float}
     */
    protected function purgeDirectorySafely(string $directory): array
    {
        $deleted = 0;
        $skipped = 0;
        $freedBytes = 0.0;
        /** @var array<int, string> $directories */
        $directories = [];

        $this->scanDirectorySafely(
            directory: $directory,
            onFile: function (SplFileInfo $item, string $pathname) use (&$directories, &$deleted, &$skipped, &$freedBytes): void {
                if ($item->isDir()) {
                    $directories[] = $pathname;

                    return;
                }

                if (! $item->isFile()) {
                    return;
                }

                $size = @$item->getSize();
                $fileBytes = ($size !== false && $size > 0) ? (float) $size : 0.0;

                try {
                    if (@unlink($pathname)) {
                        $deleted++;
                        $freedBytes += $fileBytes;
                    } else {
                        $skipped++;
                    }
                } catch (Throwable) {
                    $skipped++;
                }
            },
            onUnreadableDir: function () use (&$skipped): void {
                $skipped++;
            },
        );

        foreach (array_reverse($directories) as $subDir) {
            try {
                @rmdir($subDir);
            } catch (Throwable) {
                // Keep directories still containing active/locked files
            }
        }

        return [
            'deleted' => $deleted,
            'skipped' => $skipped,
            'freed_bytes' => $freedBytes,
        ];
    }

    /**
     * Check whether a scanned path matches protected or virtual OS directories relative to the scan root.
     * Never rejects entries when parent ancestors above $rootPath contain excluded names or if prefix cannot be aligned.
     */
    protected function isExcludedDirectory(string $currentPath, string $rootPath): bool
    {
        $normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rootPath), '/\\');
        $normalizedItem = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $currentPath);

        if ($normalizedRoot === '') {
            $relative = ltrim($normalizedItem, '/\\');
        } else {
            $prefix = $normalizedRoot . DIRECTORY_SEPARATOR;
            $prefixMatches = PHP_OS_FAMILY === 'Windows'
                ? str_starts_with(strtolower($normalizedItem), strtolower($prefix))
                : str_starts_with($normalizedItem, $prefix);

            if (! $prefixMatches) {
                return false;
            }

            $relative = substr($normalizedItem, strlen($prefix));
        }

        if ($relative === '' || $relative === false) {
            return false;
        }

        $relativeSlash = strtolower(str_replace('\\', '/', $relative));
        $segments = explode('/', $relativeSlash);

        $excludedSegments = [
            'proc', 'sys', 'dev', 'run', 'snap', 'winsxs', 'system volume information', '$recycle.bin',
        ];

        foreach ($segments as $segment) {
            if (in_array($segment, $excludedSegments, true)) {
                return true;
            }
        }

        return str_contains($relativeSlash, 'windows/winsxs')
            || str_contains($relativeSlash, '$recycle.bin')
            || str_contains($relativeSlash, 'system volume information');
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
            if ($cacheDir === '' || ! @is_dir($cacheDir)) {
                continue;
            }

            if (! @is_readable($cacheDir)) {
                $skippedFiles++;

                continue;
            }

            $stats = $this->purgeDirectorySafely($cacheDir);
            $deletedFiles += $stats['deleted'];
            $skippedFiles += $stats['skipped'];
            $freedBytes += $stats['freed_bytes'];

            if ($stats['deleted'] > 0 || $stats['skipped'] > 0 || ! in_array($browserLabel, $cleanedBrowsers, true)) {
                $cleanedBrowsers[] = (string) $browserLabel;
            }
        }

        $messageKey = $skippedFiles > 0
            ? 'messages.maintenance.browsers_clean_partial'
            : 'messages.maintenance.browsers_clean_success';

        return new ActionResult(
            success: true,
            message: $messageKey,
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
     * Safely validate and read the system hosts file (or a custom hosts path) and return parsed entries
     * wrapped in an ActionResult, returning a permission error if blocked by OS/antivirus protection.
     */
    public function getHostsEntries(?string $customPath = null): ActionResult
    {
        $hostsPath = $customPath ?? $this->getHostsFilePath();

        if ($customPath !== null) {
            try {
                if (! @is_file($hostsPath) || ! @is_readable($hostsPath)) {
                    return new ActionResult(
                        success: false,
                        message: 'messages.security.hosts_permission_denied',
                        details: [
                            'paths' => [$hostsPath],
                            'driver' => $this->getDriverLabel(),
                        ],
                    );
                }

                $raw = @file_get_contents($hostsPath);
                if ($raw === false) {
                    return new ActionResult(
                        success: false,
                        message: 'messages.security.hosts_permission_denied',
                        details: [
                            'paths' => [$hostsPath],
                            'driver' => $this->getDriverLabel(),
                        ],
                    );
                }
            } catch (Throwable) {
                return new ActionResult(
                    success: false,
                    message: 'messages.security.hosts_permission_denied',
                    details: [
                        'paths' => [$hostsPath],
                        'driver' => $this->getDriverLabel(),
                    ],
                );
            }
        } else {
            $raw = $this->readHostsFileContent();
            if ($raw === null) {
                return new ActionResult(
                    success: false,
                    message: 'messages.security.hosts_permission_denied',
                    details: [
                        'paths' => [$hostsPath],
                        'driver' => $this->getDriverLabel(),
                    ],
                );
            }
        }

        $entries = $this->inspectHostsFile($raw);

        return new ActionResult(
            success: true,
            message: 'messages.security.hosts_section',
            details: [
                'entries' => $entries,
                'count' => count($entries),
                'paths' => [$hostsPath],
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
     * Supports targeting a specific volume/directory with automatic safe fallback when read-only.
     */
    public function runSystemBenchmark(
        int $diskSizeMb = 64,
        float $cpuDurationSeconds = 1.0,
        ?string $targetDirectory = null,
    ): ActionResult {
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

        // 2. Resolve & verify writable directory for Disk Sequential Write & Read I/O Benchmark
        $fallbackTempDir = sys_get_temp_dir();
        $requestedDir = ($targetDirectory !== null && trim($targetDirectory) !== '')
            ? trim($targetDirectory)
            : $fallbackTempDir;

        if (preg_match('/^[A-Za-z]:$/', $requestedDir) === 1) {
            $requestedDir .= '\\';
        }

        $benchmarkDir = $requestedDir;
        $fallbackFrom = null;

        $probeFile = $this->buildBenchmarkTempFilePath($benchmarkDir, 'it_toolbox_probe_');
        $canWriteTarget = @is_dir($benchmarkDir) && is_resource($probeHandle = @fopen($probeFile, 'wb'));
        if ($canWriteTarget) {
            @fclose($probeHandle);
            @unlink($probeFile);
        } else {
            if ($requestedDir !== $fallbackTempDir) {
                $fallbackFrom = $requestedDir;
            }
            $benchmarkDir = $fallbackTempDir;
        }

        $tempFile = $this->buildBenchmarkTempFilePath($benchmarkDir, 'it_toolbox_bench_');
        $chunkSize = 1048576; // 1 MB
        $chunkBuffer = str_repeat(hash('sha512', $seed, true), 16384); // 64 bytes * 16384 = 1 MB

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

            if (@is_file($tempFile)) {
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
            if (@is_file($tempFile)) {
                @unlink($tempFile);
            }
        }

        $storageTier = match (true) {
            $writeSpeedMb >= 350.0 => '🚀 NVMe / Fast SSD',
            $writeSpeedMb >= 120.0 => '⚡ SATA SSD',
            default => '🐢 HDD / Standard Storage',
        };

        $details = [
            'target_disk' => $benchmarkDir,
            'cpu_score' => sprintf('%s SHA-256 ops/sec', number_format($opsPerSec)),
            'disk_write_speed' => sprintf('%.1f MB/s (%d MB sample)', $writeSpeedMb, $safeSizeMb),
            'disk_read_speed' => sprintf('%.1f MB/s (%d MB sample)', $readSpeedMb, $safeSizeMb),
            'storage_tier' => $storageTier,
            'driver' => $this->getDriverLabel(),
        ];

        if ($fallbackFrom !== null) {
            $details['fallback_from'] = $fallbackFrom;
        }

        return new ActionResult(
            success: true,
            message: 'messages.toolbox.benchmark_success',
            details: $details,
        );
    }

    /**
     * Build a unique temporary file path inside the chosen benchmark directory.
     */
    protected function buildBenchmarkTempFilePath(string $directory, string $prefix): string
    {
        $cleanDir = rtrim($directory, '\\/');
        if ($cleanDir === '') {
            return DIRECTORY_SEPARATOR . $prefix . uniqid('', true) . '.tmp';
        }

        return $cleanDir . DIRECTORY_SEPARATOR . $prefix . uniqid('', true) . '.tmp';
    }

    /**
     * Return default browser cache directories for the active OS driver.
     *
     * @return array<string, string>
     */
    abstract protected function getDefaultBrowserCachePaths(): array;

    /**
     * Return the filesystem path to the OS hosts file.
     */
    abstract protected function getHostsFilePath(): string;

    /**
     * Read the raw content of the system hosts file.
     */
    abstract protected function readHostsFileContent(): ?string;

    /**
     * Return the driver identifier ('Windows' or 'Linux').
     */
    abstract protected function getDriverLabel(): string;
}
