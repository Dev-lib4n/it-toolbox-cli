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

class KillProcessCommand extends Command
{
    use InteractsWithLocale;
    use InteractsWithTerminal;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'system:kill
                            {target? : Process PID or process name to terminate}
                            {--force : Skip confirmation prompt}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Terminate a frozen or resource-heavy process by PID, name, or interactive top-10 selection';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.system.kill_section'));

        $targetArg = $this->argument('target');
        if (is_string($targetArg) && trim($targetArg) !== '') {
            return $this->terminateDirectTarget($driver, trim($targetArg));
        }

        /** @var array<int, array{pid: int, name: string, cpu: string, memory: string}> $processes */
        $processes = spin(
            callback: fn (): array => $driver->getTopProcesses(10),
            message: (string) __('messages.spinners.system_top'),
        );

        if ($processes === []) {
            warning((string) __('messages.system.no_processes'));

            return self::SUCCESS;
        }

        $rows = [];
        $indexedProcesses = [];
        $menuOptions = [];

        foreach (array_values($processes) as $idx => $proc) {
            $num = (string) ($idx + 1);
            $indexedProcesses[$num] = $proc;
            $menuOptions[$num] = sprintf('%s (PID %d | RAM: %s)', $proc['name'], $proc['pid'], $proc['memory']);

            $rows[] = [
                sprintf('[%s]', $num),
                (string) $proc['pid'],
                $proc['name'],
                $proc['cpu'],
                $proc['memory'],
            ];
        }

        table(
            headers: [
                '#',
                (string) __('messages.system.process_headers.pid'),
                (string) __('messages.system.process_headers.name'),
                (string) __('messages.system.process_headers.cpu'),
                (string) __('messages.system.process_headers.memory'),
            ],
            rows: $rows,
        );

        if (! $this->input->isInteractive()) {
            return self::SUCCESS;
        }

        $menuOptions['0'] = (string) __('messages.common.cancelled');

        $choice = $this->promptNumericMenu(
            promptLabel: (string) __('messages.system.select_process_prompt'),
            options: $menuOptions,
            defaultKey: '0',
        );

        if ($choice === '0' || ! isset($indexedProcesses[$choice])) {
            warning((string) __('messages.common.cancelled'));

            return self::SUCCESS;
        }

        $selected = $indexedProcesses[$choice];
        $pid = (int) $selected['pid'];
        $name = (string) $selected['name'];

        if (! $this->option('force') && ! confirm(
            label: (string) __('messages.system.confirm_kill', ['pid' => $pid, 'name' => $name]),
            default: false,
            yes: (string) __('messages.common.yes'),
            no: (string) __('messages.common.no'),
        )) {
            warning((string) __('messages.common.cancelled'));

            return self::SUCCESS;
        }

        $result = spin(
            callback: fn () => $driver->killProcess($pid),
            message: (string) __('messages.spinners.killing_process', ['pid' => $pid]),
        );

        return $this->renderActionResult($result);
    }

    /**
     * Terminate a process directly by numeric PID or process name.
     */
    protected function terminateDirectTarget(SystemDriverInterface $driver, string $target): int
    {
        if (ctype_digit($target)) {
            $pid = (int) $target;

            if (! $this->option('force') && ! confirm(
                label: (string) __('messages.system.confirm_kill', ['pid' => $pid, 'name' => 'PID ' . $pid]),
                default: false,
                yes: (string) __('messages.common.yes'),
                no: (string) __('messages.common.no'),
            )) {
                warning((string) __('messages.common.cancelled'));

                return self::SUCCESS;
            }

            $result = spin(
                callback: fn () => $driver->killProcess($pid),
                message: (string) __('messages.spinners.killing_process', ['pid' => $pid]),
            );

            return $this->renderActionResult($result);
        }

        if (! $this->option('force') && ! confirm(
            label: (string) __('messages.system.confirm_kill_name', ['name' => $target]),
            default: false,
            yes: (string) __('messages.common.yes'),
            no: (string) __('messages.common.no'),
        )) {
            warning((string) __('messages.common.cancelled'));

            return self::SUCCESS;
        }

        $result = spin(
            callback: fn () => $driver->killProcessByName($target),
            message: (string) __('messages.spinners.killing_process', ['pid' => $target]),
        );

        return $this->renderActionResult($result);
    }
}
