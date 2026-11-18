<?php

declare(strict_types=1);

namespace App\Commands\Network;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

class PublicIpCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'network:public {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Retrieve external public IP address, geolocation, ISP/ASN, and reverse hostname';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.network.public_section'));

        $result = spin(
            callback: fn () => $driver->getPublicIpInfo(),
            message: (string) __('messages.spinners.network_public'),
        );

        return $this->renderActionResult($result);
    }
}
