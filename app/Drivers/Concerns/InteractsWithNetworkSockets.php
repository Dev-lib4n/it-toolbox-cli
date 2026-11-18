<?php

declare(strict_types=1);

namespace App\Drivers\Concerns;

use App\DTOs\ActionResult;
use Throwable;

trait InteractsWithNetworkSockets
{
    /**
     * Probe a remote TCP host and port and measure connection latency in milliseconds.
     *
     * @return array{host: string, port: int, status: string, latency: string, error: string}
     */
    public function probePort(string $host, int $port, int $timeout = 3): array
    {
        $cleanHost = $this->sanitizeHost($host);
        $safePort = max(1, min(65535, $port));
        $safeTimeout = max(1, $timeout);

        $errno = 0;
        $errstr = '';
        $startNs = hrtime(true);

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $cleanHost, $safePort),
            $errno,
            $errstr,
            $safeTimeout,
            STREAM_CLIENT_CONNECT
        );

        $elapsedMs = round((hrtime(true) - $startNs) / 1_000_000, 2);

        if (is_resource($socket)) {
            @fclose($socket);

            return [
                'host' => $cleanHost,
                'port' => $safePort,
                'status' => 'OPEN',
                'latency' => sprintf('%.2f ms', $elapsedMs),
                'error' => '-',
            ];
        }

        $lowerErr = strtolower($errstr);
        $isTimeout = $errno === 110
            || $errno === 10060
            || str_contains($lowerErr, 'timed out')
            || str_contains($lowerErr, 'timeout');

        return [
            'host' => $cleanHost,
            'port' => $safePort,
            'status' => $isTimeout ? 'TIMEOUT' : 'CLOSED',
            'latency' => '-',
            'error' => $errstr !== '' ? trim($errstr) : ($isTimeout ? 'Connection timed out' : 'Connection refused'),
        ];
    }

    /**
     * Inspect an HTTPS domain's SSL/TLS certificate (CN, Issuer, Validity dates, Days remaining, SANs).
     */
    public function checkSslCertificate(string $domain, int $port = 443, int $timeout = 5): ActionResult
    {
        $cleanDomain = $this->sanitizeHost($domain);
        $safePort = max(1, min(65535, $port));
        $safeTimeout = max(1, $timeout);

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $cleanDomain,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            sprintf('ssl://%s:%d', $cleanDomain, $safePort),
            $errno,
            $errstr,
            $safeTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! is_resource($client)) {
            return new ActionResult(
                success: false,
                message: 'messages.network.ssl_connect_failed',
                details: [
                    'domain' => $cleanDomain,
                    'port' => $safePort,
                    'error' => $errstr !== '' ? trim($errstr) : 'TLS handshake failed',
                ],
            );
        }

        $params = stream_context_get_params($client);
        @fclose($client);

        $certResource = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($certResource === null) {
            return new ActionResult(
                success: false,
                message: 'messages.network.ssl_connect_failed',
                details: [
                    'domain' => $cleanDomain,
                    'port' => $safePort,
                    'error' => 'No peer certificate returned by server.',
                ],
            );
        }

        $parsed = @openssl_x509_parse($certResource);
        if (! is_array($parsed)) {
            return new ActionResult(
                success: false,
                message: 'messages.network.ssl_connect_failed',
                details: [
                    'domain' => $cleanDomain,
                    'port' => $safePort,
                    'error' => 'Failed to parse X.509 certificate structure.',
                ],
            );
        }

        return $this->formatParsedSslCertificate($cleanDomain, $safePort, $parsed);
    }

    /**
     * Format a parsed openssl_x509_parse() array into a standardized ActionResult.
     *
     * @param  array<string, mixed>  $parsed
     */
    protected function formatParsedSslCertificate(string $domain, int $port, array $parsed): ActionResult
    {
        $subjectCn = (string) ($parsed['subject']['CN'] ?? $domain);
        $issuerOrg = (string) ($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? 'Unknown Issuer');
        $issuerCn = (string) ($parsed['issuer']['CN'] ?? '');
        $issuer = ($issuerCn !== '' && $issuerCn !== $issuerOrg)
            ? sprintf('%s (%s)', $issuerOrg, $issuerCn)
            : $issuerOrg;

        $validFromTs = (int) ($parsed['validFrom_time_t'] ?? 0);
        $validToTs = (int) ($parsed['validTo_time_t'] ?? 0);

        $validFrom = $validFromTs > 0 ? gmdate('Y-m-d H:i:s \U\T\C', $validFromTs) : 'N/A';
        $validTo = $validToTs > 0 ? gmdate('Y-m-d H:i:s \U\T\C', $validToTs) : 'N/A';

        $daysRemaining = (int) floor(($validToTs - time()) / 86400);
        if ($daysRemaining < 0) {
            $daysLabel = sprintf('❌ %d (%s)', $daysRemaining, (string) __('messages.network.ssl_expired'));
        } elseif ($daysRemaining < 30) {
            $daysLabel = sprintf('⚠️ %d (%s)', $daysRemaining, (string) __('messages.network.ssl_expiring_soon'));
        } else {
            $daysLabel = sprintf('✅ %d', $daysRemaining);
        }

        $rawSans = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        $sansList = [];
        foreach (explode(',', $rawSans) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'DNS:')) {
                $sansList[] = substr($part, 4);
            }
        }
        $sansFormatted = $sansList !== [] ? implode(', ', array_slice($sansList, 0, 8)) : $subjectCn;

        return new ActionResult(
            success: true,
            message: 'messages.network.ssl_valid_success',
            details: [
                'domain' => sprintf('%s:%d', $domain, $port),
                'subject_cn' => $subjectCn,
                'issuer' => $issuer,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'days_remaining' => $daysLabel,
                'sans' => $sansFormatted,
            ],
        );
    }

    /**
     * Retrieve external public IP address, geolocation, ISP/ASN, and reverse hostname.
     */
    public function getPublicIpInfo(): ActionResult
    {
        $endpoints = [
            'https://ipinfo.io/json',
            'https://api.ipify.org?format=json',
        ];

        foreach ($endpoints as $url) {
            $raw = $this->fetchPublicEndpoint($url, 4);
            if ($raw === null || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded) || empty($decoded['ip'])) {
                continue;
            }

            return $this->formatPublicIpPayload($decoded);
        }

        return new ActionResult(
            success: false,
            message: 'messages.network.public_ip_failed',
            details: [],
        );
    }

    /**
     * Format decoded JSON from ipinfo.io or ipify into a standardized ActionResult.
     *
     * @param  array<string, mixed>  $decoded
     */
    protected function formatPublicIpPayload(array $decoded): ActionResult
    {
        $ip = trim((string) ($decoded['ip'] ?? '-'));
        $locationParts = array_filter([
            trim((string) ($decoded['city'] ?? '')),
            trim((string) ($decoded['region'] ?? '')),
            trim((string) ($decoded['country'] ?? '')),
        ], fn (string $val): bool => $val !== '');

        $location = $locationParts !== [] ? implode(', ', $locationParts) : 'N/A';
        $isp = trim((string) ($decoded['org'] ?? '')) ?: 'N/A';
        $hostname = trim((string) ($decoded['hostname'] ?? ''));

        if ($hostname === '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $resolved = @gethostbyaddr($ip);
            $hostname = ($resolved !== false && $resolved !== $ip) ? $resolved : 'N/A';
        }

        return new ActionResult(
            success: true,
            message: 'messages.network.public_ip_success',
            details: [
                'public_ip' => $ip,
                'location' => $location,
                'isp' => $isp,
                'reverse_hostname' => $hostname !== '' ? $hostname : 'N/A',
            ],
        );
    }

    /**
     * Perform a fast HTTPS GET request with a 4-second timeout.
     */
    protected function fetchPublicEndpoint(string $url, int $timeout = 4): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: it-toolbox-cli\r\nAccept: application/json\r\n",
                'timeout' => $timeout,
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        try {
            $result = @file_get_contents($url, false, $context);

            return $result === false ? null : $result;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Strip scheme, path, and port suffixes from a hostname or IP string.
     */
    protected function sanitizeHost(string $host): string
    {
        $clean = trim($host);
        $clean = (string) preg_replace('#^https?://#i', '', $clean);
        $clean = explode('/', $clean)[0];

        if (substr_count($clean, ':') === 1 && ! str_starts_with($clean, '[')) {
            $clean = explode(':', $clean)[0];
        }

        return trim($clean, " \t\n\r\0\x0B[]");
    }

    /**
     * Determine whether an IPv4 and MAC pair represents a real unicast LAN neighbor (excluding multicast/broadcast).
     */
    protected function isUnicastLanNeighbor(string $ip, string $mac): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        if (str_starts_with($ip, '127.') || str_ends_with($ip, '.255') || $ip === '255.255.255.255') {
            return false;
        }

        $firstOctet = (int) explode('.', $ip)[0];
        if ($firstOctet >= 224 && $firstOctet <= 239) {
            return false;
        }

        $normalizedMac = strtolower(str_replace('-', ':', trim($mac)));
        if ($normalizedMac === '' || $normalizedMac === 'ff:ff:ff:ff:ff:ff' || $normalizedMac === '00:00:00:00:00:00' || str_starts_with($normalizedMac, '01:00:5e')) {
            return false;
        }

        return true;
    }

    /**
     * Audit key environment variables and inspect every directory entry in the system PATH.
     *
     * @return array{
     *     variables: array<string, string>,
     *     path_entries: array<int, array{index: int, path: string, status: string, icon: string}>,
     *     summary: array{valid: int, broken: int, duplicate: int}
     * }
     */
    public function auditEnvironmentPath(?string $customPathString = null): array
    {
        $rawPath = $customPathString ?? (string) (getenv('PATH') ?: ($_SERVER['PATH'] ?? ''));
        $separator = $this->getEnvPathSeparator();

        $rawEntries = $rawPath !== '' ? explode($separator, $rawPath) : [];

        $seen = [];
        $entries = [];
        $validCount = 0;
        $brokenCount = 0;
        $duplicateCount = 0;
        $index = 1;

        foreach ($rawEntries as $entry) {
            $cleanPath = trim($entry, " \t\n\r\0\x0B\"'");
            if ($cleanPath === '') {
                continue;
            }

            $normalizedKey = $separator === ';'
                ? strtolower(rtrim($cleanPath, '\\/'))
                : rtrim($cleanPath, '/');

            if (isset($seen[$normalizedKey])) {
                $status = 'DUPLICATE';
                $icon = '⚠️';
                $duplicateCount++;
            } elseif ($this->isUncNetworkPath($cleanPath)) {
                // Skip SMB/UNC probing to prevent long OS network timeout stalls
                $status = 'NETWORK_UNC';
                $icon = '🌐';
                $seen[$normalizedKey] = true;
            } elseif ($this->isAccessibleDirectory($cleanPath)) {
                $status = 'VALID';
                $icon = '✅';
                $validCount++;
                $seen[$normalizedKey] = true;
            } else {
                $status = 'BROKEN';
                $icon = '❌';
                $brokenCount++;
                $seen[$normalizedKey] = true;
            }

            $entries[] = [
                'index' => $index++,
                'path' => $cleanPath,
                'status' => $status,
                'icon' => $icon,
            ];
        }

        $variables = [
            'OS_FAMILY' => PHP_OS_FAMILY,
            'USER' => (string) (getenv('USERNAME') ?: getenv('USER') ?: get_current_user() ?: '-'),
            'HOME' => (string) (getenv('USERPROFILE') ?: getenv('HOME') ?: '-'),
            'SHELL' => (string) (getenv('SHELL') ?: getenv('ComSpec') ?: '-'),
            'TEMP' => sys_get_temp_dir(),
            'PHP_VERSION' => PHP_VERSION,
        ];

        return [
            'variables' => $variables,
            'path_entries' => $entries,
            'summary' => [
                'valid' => $validCount,
                'broken' => $brokenCount,
                'duplicate' => $duplicateCount,
            ],
        ];
    }

    /**
     * Detect UNC network share paths (`\\server\share` or `//server/share`) to skip network timeouts.
     */
    protected function isUncNetworkPath(string $path): bool
    {
        return str_starts_with($path, '\\\\') || str_starts_with($path, '//');
    }

    /**
     * Check whether a local path is an existing directory while suppressing warnings and exceptions.
     */
    protected function isAccessibleDirectory(string $path): bool
    {
        try {
            return @is_dir($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Return the OS-specific PATH entry separator (';' on Windows, ':' on Linux).
     */
    protected function getEnvPathSeparator(): string
    {
        return PATH_SEPARATOR;
    }
}
