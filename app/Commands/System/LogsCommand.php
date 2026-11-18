<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class LogsCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'system:logs
                            {--limit=15 : Maximum number of critical/error log events to retrieve}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect recent critical and error events from the OS event log / journal (last 24h)';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        $limit = max(1, (int) $this->option('limit'));

        $result = spin(
            callback: fn () => $driver->getCriticalLogs($limit),
            message: (string) __('messages.spinners.system_logs'),
        );

        info((string) __('messages.logs.section'));

        /** @var array<int, array{timestamp?: string, level?: string, source?: string, message?: string}> $events */
        $events = isset($result->details['events']) && is_array($result->details['events'])
            ? $result->details['events']
            : [];

        if ($events === []) {
            info((string) __('messages.logs.no_logs'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.logs.headers.timestamp'),
                (string) __('messages.logs.headers.level'),
                (string) __('messages.logs.headers.source'),
                (string) __('messages.logs.headers.message'),
            ],
            rows: array_map(
                fn (array $event): array => [
                    (string) ($event['timestamp'] ?? '-'),
                    (string) ($event['level'] ?? 'ERROR'),
                    (string) ($event['source'] ?? '-'),
                    (string) ($event['message'] ?? '-'),
                ],
                $events
            ),
        );

        return self::SUCCESS;
    }
}
