<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ActionResult;
use Closure;
use Phar;
use RuntimeException;
use Throwable;

class SelfUpdateService
{
    private const GITHUB_LATEST_RELEASE_URL = 'https://api.github.com/repos/Dev-lib4n/it-toolbox-cli/releases/latest';

    private const MIN_VALID_BINARY_BYTES = 512000;

    private bool $binaryReplaced = false;

    /**
     * @param  Closure|null  $httpFetcher  Optional HTTP fetcher `fn(string $url): ?string` for testing.
     * @param  string|null  $currentVersion  Optional current version override for testing.
     * @param  string|null  $runningBinaryPath  Optional Phar::running(false) override for testing.
     * @param  string|null  $osFamily  Optional PHP_OS_FAMILY override for testing.
     */
    public function __construct(
        private readonly ?Closure $httpFetcher = null,
        private readonly ?string $currentVersion = null,
        private readonly ?string $runningBinaryPath = null,
        private readonly ?string $osFamily = null,
    ) {}

    /**
     * Check GitHub Releases for a newer version of IT Toolbox CLI.
     *
     * @return array{
     *     success: bool,
     *     update_available: bool,
     *     current_version: string,
     *     latest_version: string,
     *     asset_name: string|null,
     *     asset_size: int|null,
     *     download_url: string|null,
     *     release_url: string|null,
     *     error: string|null
     * }
     */
    public function checkForUpdates(): array
    {
        $current = $this->normalizeVersion($this->getCurrentVersion());
        $targetAsset = $this->resolveTargetAssetName();

        $rawResponse = $this->fetchUrl(self::GITHUB_LATEST_RELEASE_URL);
        if ($rawResponse === null || $rawResponse === '') {
            return [
                'success' => false,
                'update_available' => false,
                'current_version' => $current,
                'latest_version' => $current,
                'asset_name' => $targetAsset,
                'asset_size' => null,
                'download_url' => null,
                'release_url' => null,
                'error' => 'Failed to reach GitHub Releases API.',
            ];
        }

        $decoded = json_decode($rawResponse, true);
        if (! is_array($decoded) || ! isset($decoded['tag_name'])) {
            return [
                'success' => false,
                'update_available' => false,
                'current_version' => $current,
                'latest_version' => $current,
                'asset_name' => $targetAsset,
                'asset_size' => null,
                'download_url' => null,
                'release_url' => null,
                'error' => 'Invalid response payload from GitHub Releases API.',
            ];
        }

        $latest = $this->normalizeVersion((string) $decoded['tag_name']);
        $updateAvailable = $this->isNewerVersion($current, $latest);
        $assetMetadata = $targetAsset !== null
            ? $this->findAssetMetadata((array) ($decoded['assets'] ?? []), $targetAsset)
            : null;

        return [
            'success' => true,
            'update_available' => $updateAvailable,
            'current_version' => $current,
            'latest_version' => $latest,
            'asset_name' => $targetAsset,
            'asset_size' => $assetMetadata['size'] ?? null,
            'download_url' => $assetMetadata['download_url'] ?? null,
            'release_url' => isset($decoded['html_url']) ? (string) $decoded['html_url'] : null,
            'error' => null,
        ];
    }

    /**
     * Download the appropriate release artifact, verify its byte integrity, and replace the current executable.
     *
     * @throws RuntimeException When the downloaded binary is incomplete or corrupted.
     */
    public function performUpdate(): ActionResult
    {
        $this->binaryReplaced = false;

        $binaryPath = $this->getRunningBinaryPath();
        if ($binaryPath === '') {
            return new ActionResult(
                success: false,
                message: 'messages.update.source_mode_warning',
                details: [
                    'current_version' => $this->normalizeVersion($this->getCurrentVersion()),
                ],
            );
        }

        $check = $this->checkForUpdates();
        if (! $check['success']) {
            return new ActionResult(
                success: false,
                message: 'messages.update.check_failed',
                details: [
                    'error' => (string) ($check['error'] ?? 'API request failed'),
                ],
            );
        }

        if (! $check['update_available']) {
            return new ActionResult(
                success: true,
                message: 'messages.update.up_to_date',
                details: [
                    'current_version' => $check['current_version'],
                    'latest_version' => $check['latest_version'],
                ],
            );
        }

        if ($check['download_url'] === null || $check['download_url'] === '') {
            return new ActionResult(
                success: false,
                message: 'messages.update.asset_not_found',
                details: [
                    'latest_version' => $check['latest_version'],
                    'asset_name' => (string) ($check['asset_name'] ?? 'unknown'),
                ],
            );
        }

        $tempPath = $binaryPath . '.download.tmp';
        @unlink($tempPath);

        if (! $this->downloadBinaryToFile($check['download_url'], $tempPath)) {
            @unlink($tempPath);

            return new ActionResult(
                success: false,
                message: 'messages.update.download_failed',
                details: [
                    'download_url' => $check['download_url'],
                ],
            );
        }

        $this->validateDownloadedBinary($tempPath, $check['asset_size']);

        @chmod($tempPath, 0755);

        $os = $this->osFamily ?? PHP_OS_FAMILY;
        $replaced = $this->replaceBinaryAtomically($tempPath, $binaryPath, $os);

        if (! $replaced) {
            @unlink($tempPath);

            return new ActionResult(
                success: false,
                message: 'messages.update.replace_failed',
                details: [
                    'paths' => [$binaryPath],
                ],
            );
        }

        $this->binaryReplaced = true;

        return new ActionResult(
            success: true,
            message: 'messages.update.updated_success',
            details: [
                'previous_version' => $check['current_version'],
                'new_version' => $check['latest_version'],
                'asset_name' => (string) $check['asset_name'],
                'paths' => [$binaryPath],
            ],
        );
    }

    /**
     * Determine whether performUpdate() successfully replaced the active binary during this process.
     */
    public function wasBinaryReplaced(): bool
    {
        return $this->binaryReplaced;
    }

    /**
     * Compare two semantic version strings and return true if $latestVersion > $currentVersion.
     */
    public function isNewerVersion(string $currentVersion, string $latestVersion): bool
    {
        $cleanCurrent = $this->normalizeVersion($currentVersion);
        $cleanLatest = $this->normalizeVersion($latestVersion);

        return version_compare($cleanLatest, $cleanCurrent, '>');
    }

    /**
     * Resolve which release artifact should be downloaded for the current runtime environment.
     * Returns null when running directly from unpacked source code.
     */
    public function resolveTargetAssetName(?string $binaryPath = null, ?string $osFamily = null): ?string
    {
        $path = $binaryPath ?? $this->getRunningBinaryPath();
        if ($path === '') {
            return null;
        }

        $os = $osFamily ?? $this->osFamily ?? PHP_OS_FAMILY;
        $lowerBase = strtolower(basename($path));

        if (str_ends_with($lowerBase, '.exe')) {
            return 'it-toolbox-windows-x64.exe';
        }

        if (str_ends_with($lowerBase, '.phar')) {
            return 'it-toolbox';
        }

        if (str_contains($lowerBase, 'linux-x64') || str_contains($lowerBase, 'linux')) {
            return 'it-toolbox-linux-x64';
        }

        if (str_contains($lowerBase, 'windows-x64')) {
            return 'it-toolbox-windows-x64.exe';
        }

        // Inspect file header if accessible on disk: ELF binary -> Linux standalone, MZ -> Windows standalone
        if (is_file($path) && is_readable($path)) {
            $header = (string) @file_get_contents($path, false, null, 0, 4);
            if (str_starts_with($header, "\x7fELF")) {
                return 'it-toolbox-linux-x64';
            }
            if (str_starts_with($header, 'MZ')) {
                return 'it-toolbox-windows-x64.exe';
            }
        }

        if ($os === 'Windows' && ! str_ends_with($lowerBase, 'it-toolbox')) {
            return 'it-toolbox-windows-x64.exe';
        }

        return 'it-toolbox';
    }

    /**
     * Remove stale `.old` backup binaries in the same directory as the active executable.
     */
    public function cleanupOldBinaries(?string $binaryPath = null): int
    {
        $path = $binaryPath ?? $this->getRunningBinaryPath();
        if ($path === '') {
            return 0;
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            return 0;
        }

        $entries = @scandir($directory);
        if (! is_array($entries)) {
            return 0;
        }

        $exactOldName = strtolower(basename($path) . '.old');
        $removed = 0;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $lowerEntry = strtolower($entry);
            if (! str_ends_with($lowerEntry, '.old')) {
                continue;
            }

            if ($lowerEntry !== $exactOldName && ! str_starts_with($lowerEntry, 'it-toolbox')) {
                continue;
            }

            $candidatePath = $directory . DIRECTORY_SEPARATOR . $entry;
            if ($candidatePath === $path || ! is_file($candidatePath)) {
                continue;
            }

            if (@unlink($candidatePath)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Normalize a version tag (e.g. "v1.2.0" -> "1.2.0").
     */
    public function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    /**
     * Get the currently configured application version.
     */
    protected function getCurrentVersion(): string
    {
        if ($this->currentVersion !== null) {
            return $this->currentVersion;
        }

        if (function_exists('config')) {
            return (string) config('app.version', '1.2.0');
        }

        return '1.2.0';
    }

    /**
     * Determine the filesystem path of the running PHAR / standalone binary.
     * Returns an empty string when running from unpacked PHP source files.
     */
    protected function getRunningBinaryPath(): string
    {
        if ($this->runningBinaryPath !== null) {
            return $this->runningBinaryPath;
        }

        return Phar::running(false);
    }

    /**
     * Locate download URL and byte size for the specified asset name in the GitHub release payload.
     *
     * @param  array<int, mixed>  $assets
     * @return array{download_url: string, size: int|null}|null
     */
    protected function findAssetMetadata(array $assets, string $targetAssetName): ?array
    {
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            if (($asset['name'] ?? '') === $targetAssetName && isset($asset['browser_download_url'])) {
                $size = isset($asset['size']) && is_numeric($asset['size']) && (int) $asset['size'] > 0
                    ? (int) $asset['size']
                    : null;

                return [
                    'download_url' => (string) $asset['browser_download_url'],
                    'size' => $size,
                ];
            }
        }

        return null;
    }

    /**
     * Validate that the downloaded temporary binary exists, exceeds the minimum sane binary size,
     * and matches the GitHub asset byte count when reported by the API.
     *
     * @throws RuntimeException When the temporary file is missing, truncated, or corrupted.
     */
    protected function validateDownloadedBinary(string $tempPath, ?int $expectedSize): void
    {
        clearstatcache(true, $tempPath);

        if (! is_file($tempPath)) {
            throw new RuntimeException('Incomplete or corrupted download: temporary binary file does not exist.');
        }

        $actualSize = @filesize($tempPath);
        if ($actualSize === false || $actualSize <= self::MIN_VALID_BINARY_BYTES) {
            @unlink($tempPath);

            throw new RuntimeException(sprintf(
                'Incomplete or corrupted download: downloaded binary size (%s bytes) must exceed %d bytes.',
                $actualSize === false ? 'unknown' : (string) $actualSize,
                self::MIN_VALID_BINARY_BYTES
            ));
        }

        if ($expectedSize !== null && $expectedSize > 0 && $actualSize !== $expectedSize) {
            @unlink($tempPath);

            throw new RuntimeException(sprintf(
                'Incomplete or corrupted download: expected %d bytes from release asset, got %d bytes.',
                $expectedSize,
                $actualSize
            ));
        }
    }

    /**
     * Replace the current executable with the downloaded temporary file.
     * On Windows, renames the locked running binary to `.old` before moving the new binary into place
     * and schedules an asynchronous background cleanup after the current process exits.
     */
    protected function replaceBinaryAtomically(string $tempPath, string $targetPath, string $osFamily): bool
    {
        if ($osFamily === 'Windows' || str_ends_with(strtolower($targetPath), '.exe')) {
            $this->cleanupOldBinaries($targetPath);

            $oldBackupPath = $targetPath . '.old';
            if (is_file($oldBackupPath)) {
                @unlink($oldBackupPath);
            }

            if (is_file($targetPath) && ! @rename($targetPath, $oldBackupPath)) {
                return false;
            }

            if (! @rename($tempPath, $targetPath)) {
                // Attempt rollback if moving the new binary failed
                if (is_file($oldBackupPath) && ! is_file($targetPath)) {
                    @rename($oldBackupPath, $targetPath);
                }

                return false;
            }

            $this->scheduleWindowsBackupDeletion($oldBackupPath, $osFamily);

            return true;
        }

        return @rename($tempPath, $targetPath);
    }

    /**
     * Spawn a non-blocking background command on Windows to delete the `.old` backup once this process exits.
     */
    protected function scheduleWindowsBackupDeletion(string $oldBackupPath, string $osFamily): void
    {
        if ($osFamily !== 'Windows' || PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        if (! function_exists('popen') || ! function_exists('pclose')) {
            return;
        }

        $command = 'start /B cmd /c "ping 127.0.0.1 -n 3 > nul & del /f /q ' . escapeshellarg($oldBackupPath) . '"';
        $handle = @popen($command, 'r');
        if (is_resource($handle)) {
            @pclose($handle);
        }
    }

    /**
     * Download a release asset cleanly (without leaving transport compression intact) directly to a temp file.
     */
    protected function downloadBinaryToFile(string $url, string $tempPath): bool
    {
        if ($this->httpFetcher !== null) {
            $content = ($this->httpFetcher)($url);
            if ($content === null) {
                return false;
            }

            return @file_put_contents($tempPath, $content) !== false;
        }

        if (function_exists('curl_init')) {
            $fp = @fopen($tempPath, 'wb');
            if ($fp === false) {
                return false;
            }

            $ch = curl_init($url);
            if ($ch === false) {
                @fclose($fp);
                @unlink($tempPath);

                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_ENCODING => '',
                CURLOPT_FAILONERROR => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: it-toolbox-cli',
                    'Accept: application/octet-stream',
                ],
            ]);

            $executed = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            @fflush($fp);
            @fclose($fp);

            if ($executed === false || $errno !== CURLE_OK) {
                @unlink($tempPath);

                return false;
            }

            return true;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'User-Agent: it-toolbox-cli',
                    'Accept: application/octet-stream',
                    'Accept-Encoding: identity',
                ]),
                'timeout' => 300,
                'follow_location' => 1,
                'max_redirects' => 10,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        try {
            $source = @fopen($url, 'rb', false, $context);
            if ($source === false) {
                return false;
            }

            $dest = @fopen($tempPath, 'wb');
            if ($dest === false) {
                @fclose($source);

                return false;
            }

            $copied = @stream_copy_to_stream($source, $dest);
            @fclose($source);
            @fflush($dest);
            @fclose($dest);

            if ($copied === false) {
                @unlink($tempPath);

                return false;
            }

            return true;
        } catch (Throwable) {
            @unlink($tempPath);

            return false;
        }
    }

    /**
     * Perform an HTTPS GET request using the injected test fetcher, cURL, or PHP's native stream context.
     */
    protected function fetchUrl(string $url): ?string
    {
        if ($this->httpFetcher !== null) {
            return ($this->httpFetcher)($url);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_ENCODING => '',
                    CURLOPT_FAILONERROR => true,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER => [
                        'User-Agent: it-toolbox-cli',
                        'Accept: application/vnd.github+json',
                    ],
                ]);

                $response = curl_exec($ch);
                $errno = curl_errno($ch);
                curl_close($ch);

                if ($response !== false && $errno === CURLE_OK) {
                    return (string) $response;
                }

                return null;
            }
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'User-Agent: it-toolbox-cli',
                    'Accept: application/vnd.github+json',
                    'Accept-Encoding: identity',
                ]),
                'timeout' => 20,
                'follow_location' => 1,
                'max_redirects' => 10,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        try {
            $response = @file_get_contents($url, false, $context);

            return $response === false ? null : $response;
        } catch (Throwable) {
            return null;
        }
    }
}
