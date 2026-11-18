<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class DiagnosticsCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'system:info {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Display operating system, CPU, memory, and storage health diagnostics';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        /** @var array{os: array<string, string>, hardware: array<string, string>, storage: array<int, array<string, string>>} $diagnostics */
        $diagnostics = spin(
            callback: fn (): array => [
                'os' => $driver->getOsInfo(),
                'hardware' => $driver->getHardwareDiagnostics(),
                'storage' => $driver->getStorageHealth(),
            ],
            message: (string) __('messages.spinners.system_info'),
        );

        info((string) __('messages.system.os_section'));
        table(
            headers: [
                (string) __('messages.common.metric'),
                (string) __('messages.common.value'),
            ],
            rows: $this->formatKeyValueRows($diagnostics['os']),
        );

        info((string) __('messages.system.hardware_section'));
        table(
            headers: [
                (string) __('messages.common.metric'),
                (string) __('messages.common.value'),
            ],
            rows: $this->formatKeyValueRows($diagnostics['hardware']),
        );

        info((string) __('messages.system.storage_section'));
        table(
            headers: [
                (string) __('messages.system.storage_headers.filesystem'),
                (string) __('messages.system.storage_headers.total'),
                (string) __('messages.system.storage_headers.used'),
                (string) __('messages.system.storage_headers.available'),
                (string) __('messages.system.storage_headers.usage_percent'),
                (string) __('messages.system.storage_headers.mount'),
            ],
            rows: array_map(
                fn (array $disk): array => [
                    $disk['filesystem'] ?? '-',
                    $disk['total'] ?? '-',
                    $disk['used'] ?? '-',
                    $disk['available'] ?? '-',
                    $disk['usage_percent'] ?? '-',
                    $disk['mount'] ?? '-',
                ],
                $diagnostics['storage']
            ),
        );

        return self::SUCCESS;
    }

    /**
     * Map associative metric keys to translated label-value rows.
     *
     * @param  array<string, string>  $metrics
     * @return array<int, array{0: string, 1: string}>
     */
    protected function formatKeyValueRows(array $metrics): array
    {
        $rows = [];

        foreach ($metrics as $key => $value) {
            $translationKey = "messages.system.fields.{$key}";
            $label = (string) __($translationKey);

            if ($label === $translationKey) {
                $label = ucfirst(str_replace('_', ' ', $key));
            }

            $rows[] = [$label, (string) $value];
        }

        return $rows;
    }
}
