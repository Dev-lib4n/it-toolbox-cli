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

class CleanTempCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'clean:temp
                            {--force : Skip confirmation prompt}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Safely clean system and user temporary directories';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.maintenance.clean_section'));

        if (! $this->option('force') && ! confirm(
            label: (string) __('messages.maintenance.confirm_clean'),
            default: true,
            yes: (string) __('messages.common.yes'),
            no: (string) __('messages.common.no'),
        )) {
            warning((string) __('messages.common.cancelled'));

            return self::SUCCESS;
        }

        $result = spin(
            callback: fn () => $driver->cleanTempFiles(),
            message: (string) __('messages.spinners.clean_temp'),
        );

        return $this->renderActionResult($result);
    }
}
