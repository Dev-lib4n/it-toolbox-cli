<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class ProcessesCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'system:top
                            {--limit=10 : Maximum number of processes to list}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect top resource-consuming processes and optionally terminate frozen tasks';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        $limit = max(1, (int) $this->option('limit'));

        /** @var array<int, array{pid: int, name: string, cpu: string, memory: string}> $processes */
        $processes = spin(
            callback: fn (): array => $driver->getTopProcesses($limit),
            message: (string) __('messages.spinners.system_top'),
        );

        info((string) __('messages.system.processes_section'));

        if ($processes === []) {
            warning((string) __('messages.system.no_processes'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.system.process_headers.pid'),
                (string) __('messages.system.process_headers.name'),
                (string) __('messages.system.process_headers.cpu'),
                (string) __('messages.system.process_headers.memory'),
            ],
            rows: array_map(
                fn (array $proc): array => [
                    (string) $proc['pid'],
                    $proc['name'],
                    $proc['cpu'],
                    $proc['memory'],
                ],
                $processes
            ),
        );

        if ($this->input->isInteractive() && confirm(
            label: (string) __('messages.system.ask_kill_prompt'),
            default: false,
            yes: (string) __('messages.common.yes'),
            no: (string) __('messages.common.no'),
        )) {
            $options = [];
            $processMap = [];

            foreach ($processes as $proc) {
                $pid = (int) $proc['pid'];
                $options[$pid] = sprintf('[PID %d] %s (CPU: %s | RAM: %s)', $pid, $proc['name'], $proc['cpu'], $proc['memory']);
                $processMap[$pid] = $proc['name'];
            }

            $selectedPid = (int) select(
                label: (string) __('messages.system.select_process_prompt'),
                options: $options,
            );

            $selectedName = $processMap[$selectedPid] ?? (string) $selectedPid;

            if (! confirm(
                label: (string) __('messages.system.confirm_kill', ['pid' => $selectedPid, 'name' => $selectedName]),
                default: false,
                yes: (string) __('messages.common.yes'),
                no: (string) __('messages.common.no'),
            )) {
                warning((string) __('messages.common.cancelled'));

                return self::SUCCESS;
            }

            $result = spin(
                callback: fn () => $driver->killProcess($selectedPid),
                message: (string) __('messages.spinners.killing_process', ['pid' => $selectedPid]),
            );

            return $this->renderActionResult($result);
        }

        return self::SUCCESS;
    }
}
