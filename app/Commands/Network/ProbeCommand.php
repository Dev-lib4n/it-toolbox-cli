<?php

declare(strict_types=1);

namespace App\Commands\Network;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

class ProbeCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'network:probe
                            {host? : Target hostname or IP address}
                            {port? : Target TCP port number (1-65535)}
                            {--timeout=3 : Connection timeout in seconds}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Test a remote TCP host and port connection and measure response latency';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.network.probe_section'));

        $hostArg = $this->argument('host');
        $host = is_string($hostArg) && trim($hostArg) !== ''
            ? trim($hostArg)
            : text(
                label: (string) __('messages.network.probe_host_prompt'),
                default: '1.1.1.1',
                required: true,
            );

        $portArg = $this->argument('port');
        $portStr = is_string($portArg) && trim($portArg) !== ''
            ? trim($portArg)
            : text(
                label: (string) __('messages.network.probe_port_prompt'),
                default: '443',
                required: true,
                validate: fn (string $val): ?string => (is_numeric($val) && (int) $val >= 1 && (int) $val <= 65535)
                    ? null
                    : (string) __('messages.network.probe_invalid_port'),
            );

        $port = max(1, min(65535, (int) $portStr));
        $timeout = max(1, (int) $this->option('timeout'));

        $result = spin(
            callback: fn (): array => $driver->probePort($host, $port, $timeout),
            message: (string) __('messages.spinners.network_probe', ['host' => $host, 'port' => $port]),
        );

        table(
            headers: [
                (string) __('messages.network.probe_headers.host'),
                (string) __('messages.network.probe_headers.port'),
                (string) __('messages.network.probe_headers.status'),
                (string) __('messages.network.probe_headers.latency'),
                (string) __('messages.network.probe_headers.details'),
            ],
            rows: [
                [
                    $result['host'],
                    (string) $result['port'],
                    $result['status'],
                    $result['latency'],
                    $result['error'],
                ],
            ],
        );

        if ($result['status'] === 'OPEN') {
            info((string) __('messages.network.probe_open_success', [
                'host' => $result['host'],
                'port' => $result['port'],
                'latency' => $result['latency'],
            ]));

            return self::SUCCESS;
        }

        error((string) __('messages.network.probe_failed', [
            'host' => $result['host'],
            'port' => $result['port'],
            'status' => $result['status'],
        ]));

        return self::FAILURE;
    }
}
