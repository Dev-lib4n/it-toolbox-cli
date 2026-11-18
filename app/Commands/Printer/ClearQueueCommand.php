<?php

declare(strict_types=1);

namespace App\Commands\Printer;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class ClearQueueCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'printer:clear
                            {--force : Skip confirmation prompt}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Restart print spooler service and purge pending print jobs';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        if (! $this->option('force') && ! confirm(
            label: (string) __('messages.printer.confirm_clear'),
            default: true,
            yes: (string) __('messages.common.yes'),
            no: (string) __('messages.common.no'),
        )) {
            warning((string) __('messages.common.cancelled'));

            return self::SUCCESS;
        }

        $result = spin(
            callback: fn () => $driver->clearPrintQueue(),
            message: (string) __('messages.spinners.printer_clear'),
        );

        return $this->renderActionResult($result);
    }
}
