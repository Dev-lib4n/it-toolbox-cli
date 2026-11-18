<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class BatteryCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'system:battery {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect laptop battery charge level, design vs full capacity, wear percentage, and cycles';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.system.battery_section'));

        $result = spin(
            callback: fn () => $driver->getBatteryHealth(),
            message: (string) __('messages.spinners.system_battery'),
        );

        if (! $result->success) {
            warning((string) __($result->message));

            return self::SUCCESS;
        }

        return $this->renderActionResult($result);
    }
}
