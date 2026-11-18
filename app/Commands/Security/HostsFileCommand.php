<?php

declare(strict_types=1);

namespace App\Commands\Security;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class HostsFileCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'security:hosts {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect the system hosts file and highlight custom or non-standard domain redirections';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.security.hosts_section'));

        if (method_exists($driver, 'getHostsEntries')) {
            $hostsResult = spin(
                callback: fn () => $driver->getHostsEntries(),
                message: (string) __('messages.spinners.security_hosts'),
            );

            if (! $hostsResult->success) {
                return $this->renderActionResult($hostsResult);
            }

            /** @var array<int, array{ip: string, host: string, status: string, icon: string}> $entries */
            $entries = is_array($hostsResult->details['entries'] ?? null)
                ? $hostsResult->details['entries']
                : [];
        } else {
            /** @var array<int, array{ip: string, host: string, status: string, icon: string}> $entries */
            $entries = spin(
                callback: fn (): array => $driver->inspectHostsFile(),
                message: (string) __('messages.spinners.security_hosts'),
            );
        }

        if ($entries === []) {
            info((string) __('messages.security.no_hosts_entries'));

            return self::SUCCESS;
        }

        $statusLabels = [
            'STANDARD' => (string) __('messages.security.hosts_states.standard'),
            'CUSTOM_LOCAL' => (string) __('messages.security.hosts_states.custom_local'),
            'EXTERNAL_REDIRECT' => (string) __('messages.security.hosts_states.external_redirect'),
        ];

        table(
            headers: [
                (string) __('messages.security.hosts_headers.ip'),
                (string) __('messages.security.hosts_headers.host'),
                (string) __('messages.security.hosts_headers.status'),
            ],
            rows: array_map(
                fn (array $item): array => [
                    $item['ip'] ?? '-',
                    $item['host'] ?? '-',
                    sprintf('%s %s', $item['icon'] ?? '', $statusLabels[$item['status']] ?? $item['status']),
                ],
                $entries
            ),
        );

        return self::SUCCESS;
    }
}
