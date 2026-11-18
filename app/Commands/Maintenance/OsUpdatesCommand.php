<?php

declare(strict_types=1);

namespace App\Commands\Maintenance;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class OsUpdatesCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'maintenance:updates {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect pending OS patches and upgradable system packages';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.maintenance.updates_section'));

        /** @var array<int, array{package: string, version: string, source: string, status: string}> $updates */
        $updates = spin(
            callback: fn (): array => $driver->getPendingUpdates(),
            message: (string) __('messages.spinners.maintenance_updates'),
        );

        if ($updates === []) {
            info((string) __('messages.maintenance.no_pending_updates'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.maintenance.updates_headers.package'),
                (string) __('messages.maintenance.updates_headers.version'),
                (string) __('messages.maintenance.updates_headers.source'),
                (string) __('messages.maintenance.updates_headers.status'),
            ],
            rows: array_map(
                fn (array $upd): array => [
                    $upd['package'] ?? '-',
                    $upd['version'] ?? '-',
                    $upd['source'] ?? '-',
                    $upd['status'] ?? '-',
                ],
                $updates
            ),
        );

        return self::SUCCESS;
    }
}
