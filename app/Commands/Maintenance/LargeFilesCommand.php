<?php

declare(strict_types=1);

namespace App\Commands\Maintenance;

use App\Commands\Concerns\InteractsWithLocale;
use App\Commands\Concerns\SelectsTargetDisk;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class LargeFilesCommand extends Command
{
    use InteractsWithLocale;
    use SelectsTargetDisk;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'maintenance:large-files
                            {path? : Target directory path to scan (defaults to user Home directory)}
                            {--limit=15 : Maximum number of largest files to list}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Scan a directory recursively to locate the largest files consuming disk space';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.maintenance.large_files_section'));

        $pathArg = $this->argument('path');
        $explicitPath = is_string($pathArg) && trim($pathArg) !== '' ? trim($pathArg) : null;
        $limit = max(1, (int) $this->option('limit'));

        $selectedTarget = $this->selectTargetDisk(
            driver: $driver,
            explicitTarget: $explicitPath,
            includeHomeOption: true,
            includeCustomPathOption: true,
        );

        $resolvedPath = $selectedTarget ?? $this->resolveUserHomeDirectory();
        if ($resolvedPath === '' || ! @is_dir($resolvedPath) || ! @is_readable($resolvedPath)) {
            error((string) __('messages.maintenance.invalid_scan_path', [
                'path' => $resolvedPath !== '' ? $resolvedPath : '(empty)',
            ]));

            return self::FAILURE;
        }

        note((string) __('messages.maintenance.large_files_target', [
            'path' => $resolvedPath,
        ]));

        try {
            /** @var array<int, array{path: string, size_bytes: float, size_formatted: string, modified_at: string}> $files */
            $files = spin(
                callback: fn (): array => $driver->findLargeFiles($resolvedPath, $limit),
                message: (string) __('messages.spinners.maintenance_large_files'),
            );
        } catch (Throwable $exception) {
            error((string) __('messages.common.unexpected_error', [
                'error' => $exception->getMessage(),
            ]));

            return self::FAILURE;
        }

        if ($files === []) {
            warning((string) __('messages.maintenance.no_large_files'));

            return self::SUCCESS;
        }

        table(
            headers: [
                '#',
                (string) __('messages.maintenance.large_files_headers.size'),
                (string) __('messages.maintenance.large_files_headers.modified'),
                (string) __('messages.maintenance.large_files_headers.path'),
            ],
            rows: array_map(
                fn (array $file, int $idx): array => [
                    (string) ($idx + 1),
                    $file['size_formatted'],
                    $file['modified_at'],
                    $file['path'],
                ],
                $files,
                array_keys($files)
            ),
        );

        return self::SUCCESS;
    }
}
