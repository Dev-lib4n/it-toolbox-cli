<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Contracts\SystemDriverInterface;
use Throwable;

use function Laravel\Prompts\text;

trait SelectsTargetDisk
{
    use InteractsWithTerminal;

    /**
     * Resolve a target drive/directory from an explicit CLI argument/option, or interactively prompt
     * the user to choose among mounted OS volumes (plus optional Home or custom directory).
     */
    protected function selectTargetDisk(
        SystemDriverInterface $driver,
        ?string $explicitTarget = null,
        bool $includeHomeOption = false,
        bool $includeCustomPathOption = true,
    ): ?string {
        if ($explicitTarget !== null && trim($explicitTarget) !== '') {
            return $this->normalizeTargetDiskPath($explicitTarget);
        }

        if (! isset($this->input) || ! $this->input->isInteractive()) {
            return null;
        }

        $volumes = $this->fetchMountedVolumes($driver);
        $options = [];
        $targetsMap = [];
        $index = 1;

        foreach ($volumes as $volume) {
            $mount = trim((string) ($volume['mount'] ?? ''));
            if ($mount === '') {
                continue;
            }

            $key = (string) $index;
            $options[$key] = (string) __('messages.disk_selector.volume_option', [
                'mount' => $mount,
                'available' => (string) ($volume['available'] ?? '-'),
                'total' => (string) ($volume['total'] ?? '-'),
            ]);
            $targetsMap[$key] = $mount;
            $index++;
        }

        if ($includeHomeOption) {
            $homePath = $this->resolveUserHomeDirectory();
            $key = (string) $index;
            $options[$key] = (string) __('messages.disk_selector.home_option', [
                'path' => $homePath,
            ]);
            $targetsMap[$key] = $homePath;
            $index++;
        } elseif ($options === []) {
            $tempPath = sys_get_temp_dir();
            $key = (string) $index;
            $options[$key] = (string) __('messages.disk_selector.system_default_option', [
                'path' => $tempPath,
            ]);
            $targetsMap[$key] = $tempPath;
            $index++;
        }

        if ($includeCustomPathOption) {
            $key = (string) $index;
            $options[$key] = (string) __('messages.disk_selector.custom_path_option');
            $targetsMap[$key] = '__CUSTOM__';
        }

        if ($options === []) {
            return null;
        }

        $choice = $this->promptNumericMenu(
            promptLabel: (string) __('messages.disk_selector.prompt'),
            options: $options,
            defaultKey: '1',
        );

        $selectedTarget = $targetsMap[$choice] ?? null;
        if ($selectedTarget === '__CUSTOM__') {
            $customPath = $this->promptForCustomDiskPath();

            return $customPath !== '' ? $this->normalizeTargetDiskPath($customPath) : null;
        }

        return is_string($selectedTarget) && $selectedTarget !== ''
            ? $this->normalizeTargetDiskPath($selectedTarget)
            : null;
    }

    /**
     * Fetch mounted storage volumes from the active OS driver safely.
     *
     * @return array<int, array{filesystem: string, total: string, used: string, available: string, usage_percent: string, mount: string}>
     */
    protected function fetchMountedVolumes(SystemDriverInterface $driver): array
    {
        try {
            return $driver->getStorageHealth();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Prompt the user for a custom target path or drive letter.
     */
    protected function promptForCustomDiskPath(): string
    {
        $entered = text(
            label: (string) __('messages.disk_selector.custom_path_prompt'),
            required: true,
        );

        return trim((string) $entered);
    }

    /**
     * Resolve the current user's Home directory path across Windows and Unix/Linux.
     */
    protected function resolveUserHomeDirectory(): string
    {
        $home = (string) (getenv('USERPROFILE') ?: getenv('HOME') ?: '');
        if (trim($home) !== '') {
            return trim($home);
        }

        return getcwd() ?: base_path();
    }

    /**
     * Normalize drive-letter shortcuts (e.g., "D:" -> "D:\") so directory functions resolve the drive root.
     */
    protected function normalizeTargetDiskPath(string $rawPath): string
    {
        $trimmed = trim($rawPath);
        if (preg_match('/^[A-Za-z]:$/', $trimmed) === 1) {
            return $trimmed . '\\';
        }

        return $trimmed;
    }
}
