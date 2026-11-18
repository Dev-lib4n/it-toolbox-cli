<?php

declare(strict_types=1);

namespace App\Commands\Printer;

use App\Commands\Concerns\InteractsWithLocale;
use App\Commands\Concerns\InteractsWithTerminal;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class PrintTestPageCommand extends Command
{
    use InteractsWithLocale;
    use InteractsWithTerminal;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'printer:test-page
                            {printer? : Name of the printer to receive the test page}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Send a diagnostic test page to an installed printer';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.printer.test_page_section'));

        $printerArg = $this->argument('printer');
        if (is_string($printerArg) && trim($printerArg) !== '') {
            $selectedPrinter = trim($printerArg);
        } else {
            /** @var array<int, array{name: string, status: string, default: string, driver: string, port: string}> $printers */
            $printers = spin(
                callback: fn (): array => $driver->listPrinters(),
                message: (string) __('messages.spinners.printer_list'),
            );

            if ($printers === []) {
                warning((string) __('messages.printer.no_printers'));

                return self::SUCCESS;
            }

            $menuOptions = [];
            $indexedPrinters = [];
            foreach (array_values($printers) as $idx => $p) {
                $num = (string) ($idx + 1);
                $indexedPrinters[$num] = $p['name'];
                $defaultBadge = strtolower($p['default'] ?? '') === 'yes' ? ' [*]' : '';
                $menuOptions[$num] = sprintf('%s%s (%s)', $p['name'], $defaultBadge, $p['status'] ?? 'Ready');
            }
            $menuOptions['0'] = (string) __('messages.common.cancelled');

            $choice = $this->promptNumericMenu(
                promptLabel: (string) __('messages.printer.select_printer_prompt'),
                options: $menuOptions,
                defaultKey: '1',
            );

            if ($choice === '0' || ! isset($indexedPrinters[$choice])) {
                warning((string) __('messages.common.cancelled'));

                return self::SUCCESS;
            }

            $selectedPrinter = $indexedPrinters[$choice];
        }

        $result = spin(
            callback: fn () => $driver->printTestPage($selectedPrinter),
            message: (string) __('messages.spinners.printer_test_page', ['printer' => $selectedPrinter]),
        );

        return $this->renderActionResult($result);
    }
}
