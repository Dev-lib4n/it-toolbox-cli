<?php

declare(strict_types=1);

namespace App\Commands\Network;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class SslCheckCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'network:ssl
                            {domain? : Target HTTPS domain name}
                            {--port=443 : Target HTTPS TCP port}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Inspect an HTTPS domain SSL/TLS certificate (Subject, Issuer, Expiration, Days Remaining, SANs)';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.network.ssl_section'));

        $domainArg = $this->argument('domain');
        $domain = is_string($domainArg) && trim($domainArg) !== ''
            ? trim($domainArg)
            : text(
                label: (string) __('messages.network.ssl_domain_prompt'),
                default: 'google.com',
                required: true,
            );

        $port = max(1, min(65535, (int) $this->option('port')));

        $result = spin(
            callback: fn () => $driver->checkSslCertificate($domain, $port),
            message: (string) __('messages.spinners.network_ssl', ['domain' => $domain]),
        );

        return $this->renderActionResult($result);
    }
}
