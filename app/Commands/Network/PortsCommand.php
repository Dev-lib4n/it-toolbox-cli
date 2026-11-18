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

class PortsCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'network:ports {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect active listening TCP and UDP ports';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        /** @var array<int, array{protocol: string, local_address: string, port: string, pid: string, process: string}> $ports */
        $ports = spin(
            callback: fn (): array => $driver->getListeningPorts(),
            message: (string) __('messages.spinners.network_ports'),
        );

        info((string) __('messages.network.ports_section'));

        if ($ports === []) {
            warning((string) __('messages.network.no_ports'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.network.port_headers.protocol'),
                (string) __('messages.network.port_headers.local_address'),
                (string) __('messages.network.port_headers.port'),
                (string) __('messages.network.port_headers.pid'),
                (string) __('messages.network.port_headers.process'),
            ],
            rows: array_map(
                fn (array $entry): array => [
                    $entry['protocol'] ?? '-',
                    $entry['local_address'] ?? '-',
                    $entry['port'] ?? '-',
                    $entry['pid'] ?? '-',
                    $entry['process'] ?? '-',
                ],
                $ports
            ),
        );

        return self::SUCCESS;
    }
}
