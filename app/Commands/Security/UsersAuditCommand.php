<?php

declare(strict_types=1);

namespace App\Commands\Security;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class UsersAuditCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'security:users {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Audit local user accounts, active status, last logon, and Administrative/sudo privileges';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.security.users_section'));

        /** @var array<int, array{username: string, status: string, is_admin: string, last_logon: string}> $users */
        $users = spin(
            callback: fn (): array => $driver->getLocalUsersAudit(),
            message: (string) __('messages.spinners.security_users'),
        );

        if ($users === []) {
            warning((string) __('messages.security.no_users_found'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.security.users_headers.username'),
                (string) __('messages.security.users_headers.status'),
                (string) __('messages.security.users_headers.is_admin'),
                (string) __('messages.security.users_headers.last_logon'),
            ],
            rows: array_map(
                fn (array $user): array => [
                    $user['username'] ?? '-',
                    $user['status'] ?? '-',
                    $user['is_admin'] ?? 'No',
                    $user['last_logon'] ?? '-',
                ],
                $users
            ),
        );

        return self::SUCCESS;
    }
}
