<?php

declare(strict_types=1);

namespace App\Commands\Maintenance;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class OsRepairCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'maintenance:repair
                            {--force : Skip confirmation prompt}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Verify and repair OS system files and package integrity (SFC & DISM / dpkg / rpm / pacman)';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.maintenance.repair_section'));

        if (! $this->option('force') && ! confirm(
            label: (string) __('messages.maintenance.confirm_repair'),
            default: false,
            yes: (string) __('messages.common.yes'),
            no: (string) __('messages.common.no'),
        )) {
            warning((string) __('messages.common.cancelled'));

            return self::SUCCESS;
        }

        $result = spin(
            callback: fn () => $driver->repairOsIntegrity(),
            message: (string) __('messages.spinners.maintenance_repair'),
        );

        return $this->renderActionResult($result);
    }
}
