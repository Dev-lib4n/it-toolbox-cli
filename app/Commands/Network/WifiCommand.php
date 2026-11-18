<?php

declare(strict_types=1);

namespace App\Commands\Network;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class WifiCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'network:wifi {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Display wireless (Wi-Fi) adapter signal, SSID, BSSID, channel, band, and link speed';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.network.wifi_section'));

        $result = spin(
            callback: fn () => $driver->getWifiInfo(),
            message: (string) __('messages.spinners.network_wifi'),
        );

        if (! $result->success) {
            warning((string) __($result->message));

            return self::SUCCESS;
        }

        return $this->renderActionResult($result);
    }
}
