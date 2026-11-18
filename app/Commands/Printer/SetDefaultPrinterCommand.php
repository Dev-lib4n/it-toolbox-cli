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

class SetDefaultPrinterCommand extends Command
{
    use InteractsWithLocale;
    use InteractsWithTerminal;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'printer:default
                            {printer? : Name of the printer to set as default}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Set the system default printer from the list of installed printers';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.printer.default_section'));

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
                $defaultBadge = strtolower($p['default'] ?? '') === 'yes' ? ' (*) [Current Default]' : '';
                $menuOptions[$num] = sprintf('%s%s', $p['name'], $defaultBadge);
            }
            $menuOptions['0'] = (string) __('messages.common.cancelled');

            $choice = $this->promptNumericMenu(
                promptLabel: (string) __('messages.printer.select_default_prompt'),
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
            callback: fn () => $driver->setDefaultPrinter($selectedPrinter),
            message: (string) __('messages.spinners.printer_default', ['printer' => $selectedPrinter]),
        );

        return $this->renderActionResult($result);
    }
}
