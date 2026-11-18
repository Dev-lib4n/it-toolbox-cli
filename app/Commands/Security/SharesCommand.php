<?php

declare(strict_types=1);

namespace App\Commands\Security;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class SharesCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'security:shares {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'List active SMB, Samba, and NFS network shares exposed by this system';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.security.shares_section'));

        /** @var array<int, array{name: string, path: string, type: string, description: string}> $shares */
        $shares = spin(
            callback: fn (): array => $driver->getNetworkShares(),
            message: (string) __('messages.spinners.security_shares'),
        );

        if ($shares === []) {
            info((string) __('messages.security.no_shares_found'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.security.shares_headers.name'),
                (string) __('messages.security.shares_headers.path'),
                (string) __('messages.security.shares_headers.type'),
                (string) __('messages.security.shares_headers.description'),
            ],
            rows: array_map(
                fn (array $share): array => [
                    $share['name'] ?? '-',
                    $share['path'] ?? '-',
                    $share['type'] ?? '-',
                    $share['description'] ?? '-',
                ],
                $shares
            ),
        );

        return self::SUCCESS;
    }
}
