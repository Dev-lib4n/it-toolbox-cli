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

class NetCheckCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'network:check {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect active network interfaces and run connectivity health checks';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        /** @var array{interfaces: array<int, array<string, string>>, connectivity: array<int, array<string, string>>} $networkData */
        $networkData = spin(
            callback: fn (): array => [
                'interfaces' => $driver->getNetworkInterfaces(),
                'connectivity' => $driver->runConnectivityHealthCheck(),
            ],
            message: (string) __('messages.spinners.network_check'),
        );

        info((string) __('messages.network.interfaces_section'));

        if ($networkData['interfaces'] === []) {
            warning((string) __('messages.network.no_interfaces'));
        } else {
            table(
                headers: [
                    (string) __('messages.network.interface_headers.interface'),
                    (string) __('messages.network.interface_headers.ipv4'),
                    (string) __('messages.network.interface_headers.ipv6'),
                    (string) __('messages.network.interface_headers.mac'),
                    (string) __('messages.network.interface_headers.status'),
                ],
                rows: array_map(
                    fn (array $iface): array => [
                        $iface['interface'] ?? '-',
                        $iface['ipv4'] ?? '-',
                        $iface['ipv6'] ?? '-',
                        $iface['mac'] ?? '-',
                        $iface['status'] ?? '-',
                    ],
                    $networkData['interfaces']
                ),
            );
        }

        info((string) __('messages.network.connectivity_section'));

        table(
            headers: [
                (string) __('messages.network.connectivity_headers.target'),
                (string) __('messages.network.connectivity_headers.check'),
                (string) __('messages.network.connectivity_headers.status'),
                (string) __('messages.network.connectivity_headers.latency'),
            ],
            rows: array_map(
                fn (array $check): array => [
                    $check['target'] ?? '-',
                    $check['check'] ?? '-',
                    $check['status'] ?? '-',
                    $check['latency'] ?? '-',
                ],
                $networkData['connectivity']
            ),
        );

        return self::SUCCESS;
    }
}
