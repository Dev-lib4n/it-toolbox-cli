<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Commands\Concerns\InteractsWithTerminal;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class ServicesCommand extends Command
{
    use InteractsWithLocale;
    use InteractsWithTerminal;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'system:services
                            {--restart= : Service name to restart immediately}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect critical IT support services and optionally restart a service';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.system.services_section'));

        $restartTarget = $this->option('restart');
        if (is_string($restartTarget) && trim($restartTarget) !== '') {
            $target = trim($restartTarget);
            $result = spin(
                callback: fn () => $driver->restartService($target),
                message: (string) __('messages.spinners.restarting_service', ['service' => $target]),
            );

            return $this->renderActionResult($result);
        }

        /** @var array<int, array{name: string, display_name: string, status: string, start_type: string}> $services */
        $services = spin(
            callback: fn (): array => $driver->getCriticalServices(),
            message: (string) __('messages.spinners.system_services'),
        );

        if ($services === []) {
            warning((string) __('messages.system.no_services'));

            return self::SUCCESS;
        }

        $rows = [];
        $indexedMap = [];
        foreach (array_values($services) as $idx => $svc) {
            $num = (string) ($idx + 1);
            $indexedMap[$num] = $svc['name'];
            $statusBadge = strtolower($svc['status']) === 'running'
                ? '✅ ' . $svc['status']
                : '⚠️ ' . $svc['status'];

            $rows[] = [
                sprintf('[%s]', $num),
                $svc['name'],
                $svc['display_name'],
                $statusBadge,
                $svc['start_type'],
            ];
        }

        table(
            headers: [
                '#',
                (string) __('messages.system.service_headers.name'),
                (string) __('messages.system.service_headers.display_name'),
                (string) __('messages.system.service_headers.status'),
                (string) __('messages.system.service_headers.start_type'),
            ],
            rows: $rows,
        );

        if ($this->input->isInteractive() && confirm(
            label: (string) __('messages.system.ask_restart_service'),
            default: false,
            yes: (string) __('messages.common.yes'),
            no: (string) __('messages.common.no'),
        )) {
            $menuOptions = [];
            foreach ($indexedMap as $num => $name) {
                $menuOptions[$num] = $name;
            }
            $menuOptions['0'] = (string) __('messages.common.cancelled');

            $choice = $this->promptNumericMenu(
                promptLabel: (string) __('messages.system.select_service_prompt'),
                options: $menuOptions,
                defaultKey: '1',
            );

            if ($choice === '0' || ! isset($indexedMap[$choice])) {
                return self::SUCCESS;
            }

            $selectedService = $indexedMap[$choice];
            $result = spin(
                callback: fn () => $driver->restartService($selectedService),
                message: (string) __('messages.spinners.restarting_service', ['service' => $selectedService]),
            );

            return $this->renderActionResult($result);
        }

        return self::SUCCESS;
    }
}
