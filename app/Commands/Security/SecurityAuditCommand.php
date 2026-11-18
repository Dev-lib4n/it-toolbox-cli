<?php

declare(strict_types=1);

namespace App\Commands\Security;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class SecurityAuditCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'security:audit {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Run a unified security posture audit (Firewall, Antivirus/Defender/MAC, and UAC/Sudo/SSH policy)';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.security.audit_section'));

        /** @var array<int, array{component: string, status: string, icon: string, details: string}> $checks */
        $checks = spin(
            callback: fn (): array => $driver->runSecurityAudit(),
            message: (string) __('messages.spinners.security_audit'),
        );

        table(
            headers: [
                (string) __('messages.security.audit_headers.component'),
                (string) __('messages.security.audit_headers.status'),
                (string) __('messages.security.audit_headers.details'),
            ],
            rows: array_map(
                fn (array $item): array => [
                    $item['component'] ?? '-',
                    sprintf('%s %s', $item['icon'] ?? '', $item['status'] ?? '-'),
                    $item['details'] ?? '-',
                ],
                $checks
            ),
        );

        return self::SUCCESS;
    }
}
