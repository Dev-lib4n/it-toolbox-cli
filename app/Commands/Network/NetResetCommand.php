<?php

declare(strict_types=1);

namespace App\Commands\Network;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class NetResetCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'network:reset
                            {--force : Skip confirmation prompt}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Perform a full network stack reset (Winsock/IP reset on Windows; NetworkManager/DHCP on Linux)';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.network.reset_section'));

        if (! $this->option('force') && ! confirm(
            label: (string) __('messages.network.confirm_reset'),
            default: false,
            yes: (string) __('messages.common.yes'),
            no: (string) __('messages.common.no'),
        )) {
            warning((string) __('messages.common.cancelled'));

            return self::SUCCESS;
        }

        $result = spin(
            callback: fn () => $driver->resetNetworkStack(),
            message: (string) __('messages.spinners.network_reset'),
        );

        return $this->renderActionResult($result);
    }
}
