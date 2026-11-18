<?php

declare(strict_types=1);

namespace App\Commands\Maintenance;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class SchedulerCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'maintenance:scheduler {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'List active non-system scheduled tasks, cron jobs, and systemd timers';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.maintenance.scheduler_section'));

        /** @var array<int, array{name: string, schedule: string, next_run: string, status: string}> $tasks */
        $tasks = spin(
            callback: fn (): array => $driver->getScheduledTasks(),
            message: (string) __('messages.spinners.maintenance_scheduler'),
        );

        if ($tasks === []) {
            warning((string) __('messages.maintenance.no_scheduled_tasks'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.maintenance.scheduler_headers.name'),
                (string) __('messages.maintenance.scheduler_headers.schedule'),
                (string) __('messages.maintenance.scheduler_headers.next_run'),
                (string) __('messages.maintenance.scheduler_headers.status'),
            ],
            rows: array_map(
                fn (array $task): array => [
                    $task['name'] ?? '-',
                    $task['schedule'] ?? '-',
                    $task['next_run'] ?? '-',
                    $task['status'] ?? '-',
                ],
                $tasks
            ),
        );

        return self::SUCCESS;
    }
}
