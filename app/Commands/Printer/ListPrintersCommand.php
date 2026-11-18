<?php

declare(strict_types=1);

namespace App\Commands\Printer;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class ListPrintersCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'printer:list {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'List installed printers and their current status';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        /** @var array<int, array{name: string, status: string, default: string, driver: string, port: string}> $printers */
        $printers = spin(
            callback: fn (): array => $driver->listPrinters(),
            message: (string) __('messages.spinners.printer_list'),
        );

        info((string) __('messages.printer.list_section'));

        if ($printers === []) {
            warning((string) __('messages.printer.no_printers'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.printer.headers.name'),
                (string) __('messages.printer.headers.status'),
                (string) __('messages.printer.headers.default'),
                (string) __('messages.printer.headers.driver'),
                (string) __('messages.printer.headers.port'),
            ],
            rows: array_map(
                fn (array $printer): array => [
                    $printer['name'] ?? '-',
                    $printer['status'] ?? '-',
                    $printer['default'] ?? '-',
                    $printer['driver'] ?? '-',
                    $printer['port'] ?? '-',
                ],
                $printers
            ),
        );

        return self::SUCCESS;
    }
}
