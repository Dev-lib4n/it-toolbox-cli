<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ActionResult;
use Closure;
use Phar;
use Throwable;

class SelfUpdateService
{
    private const GITHUB_LATEST_RELEASE_URL = 'https://api.github.com/repos/Dev-lib4n/it-toolbox-cli/releases/latest';

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
                'download_url' => null,
                'release_url' => null,
                'error' => 'Invalid response payload from GitHub Releases API.',
            ];
        }

        $latest = $this->normalizeVersion((string) $decoded['tag_name']);
        $updateAvailable = $this->isNewerVersion($current, $latest);
        $downloadUrl = $targetAsset !== null
            ? $this->findAssetDownloadUrl((array) ($decoded['assets'] ?? []), $targetAsset)
            : null;

        return [
            'success' => true,
            'update_available' => $updateAvailable,
            'current_version' => $current,
            'latest_version' => $latest,
            'asset_name' => $targetAsset,
            'download_url' => $downloadUrl,
            'release_url' => isset($decoded['html_url']) ? (string) $decoded['html_url'] : null,
            'error' => null,
        ];
    }

    /**
     * Download the appropriate release artifact and atomically replace the current executable.
     */
    public function performUpdate(): ActionResult
    {
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

        $binaryContent = $this->fetchUrl($check['download_url']);
        if ($binaryContent === null || $binaryContent === '') {
            return new ActionResult(
                success: false,
                message: 'messages.update.download_failed',
                details: [
                    'download_url' => $check['download_url'],
                ],
            );
        }

        $tempPath = $binaryPath . '.download.tmp';
        $written = @file_put_contents($tempPath, $binaryContent);
        if ($written === false || $written <= 0) {
            @unlink($tempPath);

            return new ActionResult(
                success: false,
                message: 'messages.update.replace_failed',
                details: [
                    'paths' => [$binaryPath],
                ],
            );
        }

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
     * Locate the browser_download_url for the specified asset name in the GitHub release payload.
     *
     * @param  array<int, mixed>  $assets
     */
    protected function findAssetDownloadUrl(array $assets, string $targetAssetName): ?string
    {
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            if (($asset['name'] ?? '') === $targetAssetName && isset($asset['browser_download_url'])) {
                return (string) $asset['browser_download_url'];
            }
        }

        return null;
    }

    /**
     * Replace the current executable with the downloaded temporary file.
     * On Windows, renames the locked running binary to `.old` before moving the new binary into place.
     */
    protected function replaceBinaryAtomically(string $tempPath, string $targetPath, string $osFamily): bool
    {
        if ($osFamily === 'Windows' || str_ends_with(strtolower($targetPath), '.exe')) {
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

            return true;
        }

        return @rename($tempPath, $targetPath);
    }

    /**
     * Perform an HTTPS GET request using the injected test fetcher or PHP's native stream context.
     */
    protected function fetchUrl(string $url): ?string
    {
        if ($this->httpFetcher !== null) {
            return ($this->httpFetcher)($url);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'User-Agent: it-toolbox-cli',
                    'Accept: application/vnd.github+json',
                ]),
                'timeout' => 20,
                'follow_location' => 1,
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
