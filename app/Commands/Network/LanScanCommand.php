<?php

declare(strict_types=1);

namespace App\Commands\Network;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class LanScanCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'network:scan {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Discover active devices on the local area network (ARP cache & neighbor table)';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.network.scan_section'));

        /** @var array<int, array{ip: string, mac: string, status: string, interface: string}> $devices */
        $devices = spin(
            callback: fn (): array => $driver->scanLanDevices(),
            message: (string) __('messages.spinners.network_scan'),
        );

        if ($devices === []) {
            warning((string) __('messages.network.no_lan_devices'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.network.scan_headers.ip'),
                (string) __('messages.network.scan_headers.mac'),
                (string) __('messages.network.scan_headers.status'),
                (string) __('messages.network.scan_headers.interface'),
            ],
            rows: array_map(
                fn (array $device): array => [
                    $device['ip'] ?? '-',
                    $device['mac'] ?? '-',
                    $device['status'] ?? '-',
                    $device['interface'] ?? '-',
                ],
                $devices
            ),
        );

        return self::SUCCESS;
    }
}
