<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class StartupCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'system:startup {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'List programs and services configured to run at system boot / user login';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.system.startup_section'));

        /** @var array<int, array{name: string, command: string, location: string, user: string}> $entries */
        $entries = spin(
            callback: fn (): array => $driver->getStartupPrograms(),
            message: (string) __('messages.spinners.system_startup'),
        );

        if ($entries === []) {
            warning((string) __('messages.system.no_startup_items'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.system.startup_headers.name'),
                (string) __('messages.system.startup_headers.command'),
                (string) __('messages.system.startup_headers.location'),
                (string) __('messages.system.startup_headers.user'),
            ],
            rows: array_map(
                fn (array $item): array => [
                    $item['name'] ?? '-',
                    $item['command'] ?? '-',
                    $item['location'] ?? '-',
                    $item['user'] ?? '-',
                ],
                $entries
            ),
        );

        return self::SUCCESS;
    }
}
