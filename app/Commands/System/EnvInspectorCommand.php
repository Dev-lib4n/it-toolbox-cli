<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class EnvInspectorCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'system:env {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect system environment variables and audit PATH directories for missing or duplicate entries';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        $audit = spin(
            callback: fn (): array => $driver->auditEnvironmentPath(),
            message: (string) __('messages.spinners.system_env'),
        );

        info((string) __('messages.system.env_section'));

        $varRows = [];
        foreach ($audit['variables'] as $key => $val) {
            $varRows[] = [$key, $val];
        }

        table(
            headers: [
                (string) __('messages.common.metric'),
                (string) __('messages.common.value'),
            ],
            rows: $varRows,
        );

        info((string) __('messages.system.path_audit_section'));

        $statusLabels = [
            'VALID' => (string) __('messages.system.path_states.valid'),
            'BROKEN' => (string) __('messages.system.path_states.broken'),
            'DUPLICATE' => (string) __('messages.system.path_states.duplicate'),
        ];

        table(
            headers: [
                '#',
                (string) __('messages.system.path_headers.directory'),
                (string) __('messages.system.path_headers.status'),
            ],
            rows: array_map(
                fn (array $entry): array => [
                    (string) $entry['index'],
                    $entry['path'],
                    sprintf('%s %s', $entry['icon'], $statusLabels[$entry['status']] ?? $entry['status']),
                ],
                $audit['path_entries']
            ),
        );

        note((string) __('messages.system.path_summary', [
            'valid' => $audit['summary']['valid'],
            'broken' => $audit['summary']['broken'],
            'duplicate' => $audit['summary']['duplicate'],
        ]));

        return self::SUCCESS;
    }
}
