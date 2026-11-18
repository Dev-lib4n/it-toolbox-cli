<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SystemDriverInterface;
use App\DTOs\ActionResult;

class ReportExporterService
{
    public function __construct(
        private readonly SystemDriverInterface $driver,
    ) {}

    /**
     * Collect diagnostic data from all required driver modules.
     *
     * @return array{
     *     generated_at: string,
     *     locale: string,
     *     os_family: string,
     *     os_info: array<string, string>,
     *     hardware: array<string, string>,
     *     storage: array<int, array<string, string>>,
     *     network_interfaces: array<int, array<string, string>>,
     *     connectivity: array<int, array<string, string>>,
     *     printers: array<int, array<string, string>>
     * }
     */
    public function collectDiagnostics(?string $locale = null): array
    {
        $activeLocale = $locale ?? (app()->getLocale() ?: 'en');

        return [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'locale' => $activeLocale,
            'os_family' => PHP_OS_FAMILY,
            'os_info' => $this->driver->getOsInfo(),
            'hardware' => $this->driver->getHardwareDiagnostics(),
            'storage' => $this->driver->getStorageHealth(),
            'network_interfaces' => $this->driver->getNetworkInterfaces(),
            'connectivity' => $this->driver->runConnectivityHealthCheck(),
            'printers' => $this->driver->listPrinters(),
        ];
    }

    /**
     * Export the diagnostic report to Markdown (.md), JSON (.json), or both.
     *
     * @param  string  $format  'md', 'json', or 'both'
     * @param  string|null  $outputDirectory  Target directory (defaults to current working directory)
     * @param  string|null  $locale  Target locale ('en' or 'pt_BR')
     * @param  string|null  $timestamp  Optional fixed timestamp in 'Y-m-d-His' format
     */
    public function export(
        string $format = 'both',
        ?string $outputDirectory = null,
        ?string $locale = null,
        ?string $timestamp = null,
    ): ActionResult {
        $activeLocale = $locale ?? (app()->getLocale() ?: 'en');
        app()->setLocale($activeLocale);
        if (app()->bound('translator')) {
            app('translator')->setLocale($activeLocale);
        }

        $normalizedFormat = strtolower(trim($format));
        if ($normalizedFormat === 'markdown') {
            $normalizedFormat = 'md';
        }
        if (! in_array($normalizedFormat, ['md', 'json', 'both'], true)) {
            $normalizedFormat = 'both';
        }

        $directory = rtrim($outputDirectory ?: (getcwd() ?: base_path()), '/\\');
        $stamp = $timestamp ?? date('Y-m-d-His');
        $baseFilename = sprintf('it-toolbox-report-%s', $stamp);

        $data = $this->collectDiagnostics($activeLocale);
        $writtenFiles = [];

        if ($normalizedFormat === 'md' || $normalizedFormat === 'both') {
            $mdPath = $directory . DIRECTORY_SEPARATOR . $baseFilename . '.md';
            $mdContent = $this->toMarkdown($data, $activeLocale);
            if (@file_put_contents($mdPath, $mdContent) === false) {
                return new ActionResult(
                    success: false,
                    message: 'messages.report.exported_failed',
                    details: ['paths' => [$mdPath]],
                );
            }
            $writtenFiles[] = $mdPath;
        }

        if ($normalizedFormat === 'json' || $normalizedFormat === 'both') {
            $jsonPath = $directory . DIRECTORY_SEPARATOR . $baseFilename . '.json';
            $jsonContent = $this->toJson($data);
            if (@file_put_contents($jsonPath, $jsonContent) === false) {
                return new ActionResult(
                    success: false,
                    message: 'messages.report.exported_failed',
                    details: ['paths' => [$jsonPath]],
                );
            }
            $writtenFiles[] = $jsonPath;
        }

        return new ActionResult(
            success: true,
            message: 'messages.report.exported_success',
            details: [
                'paths' => $writtenFiles,
            ],
        );
    }

    /**
     * Render the diagnostic payload into a localized Markdown document.
     *
     * @param  array<string, mixed>  $data
     */
    public function toMarkdown(array $data, ?string $locale = null): string
    {
        $activeLocale = $locale ?? (string) ($data['locale'] ?? app()->getLocale() ?: 'en');
        app()->setLocale($activeLocale);
        if (app()->bound('translator')) {
            app('translator')->setLocale($activeLocale);
        }

        $metricHeader = (string) __('messages.common.metric');
        $valueHeader = (string) __('messages.common.value');

        $lines = [
            sprintf('# %s — %s', (string) __('messages.app.name'), (string) __('messages.app.tagline')),
            '',
            sprintf('- **%s:** `%s`', (string) __('messages.app.detected_os', ['os' => (string) ($data['os_family'] ?? PHP_OS_FAMILY)]), (string) ($data['generated_at'] ?? gmdate('c'))),
            '',
            sprintf('## %s', (string) __('messages.system.os_section')),
            '',
            sprintf('| %s | %s |', $metricHeader, $valueHeader),
            '| :--- | :--- |',
        ];

        foreach ((array) ($data['os_info'] ?? []) as $key => $value) {
            $labelKey = "messages.system.fields.{$key}";
            $label = (string) __($labelKey);
            if ($label === $labelKey) {
                $label = ucfirst(str_replace('_', ' ', (string) $key));
            }
            $lines[] = sprintf('| %s | %s |', $label, (string) $value);
        }

        $lines[] = '';
        $lines[] = sprintf('## %s', (string) __('messages.system.hardware_section'));
        $lines[] = '';
        $lines[] = sprintf('| %s | %s |', $metricHeader, $valueHeader);
        $lines[] = '| :--- | :--- |';

        foreach ((array) ($data['hardware'] ?? []) as $key => $value) {
            $labelKey = "messages.system.fields.{$key}";
            $label = (string) __($labelKey);
            if ($label === $labelKey) {
                $label = ucfirst(str_replace('_', ' ', (string) $key));
            }
            $lines[] = sprintf('| %s | %s |', $label, (string) $value);
        }

        $lines[] = '';
        $lines[] = sprintf('## %s', (string) __('messages.system.storage_section'));
        $lines[] = '';
        $lines[] = sprintf(
            '| %s | %s | %s | %s | %s | %s |',
            (string) __('messages.system.storage_headers.filesystem'),
            (string) __('messages.system.storage_headers.total'),
            (string) __('messages.system.storage_headers.used'),
            (string) __('messages.system.storage_headers.available'),
            (string) __('messages.system.storage_headers.usage_percent'),
            (string) __('messages.system.storage_headers.mount'),
        );
        $lines[] = '| :--- | :--- | :--- | :--- | :--- | :--- |';

        foreach ((array) ($data['storage'] ?? []) as $disk) {
            if (! is_array($disk)) {
                continue;
            }
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $disk['filesystem'] ?? '-',
                $disk['total'] ?? '-',
                $disk['used'] ?? '-',
                $disk['available'] ?? '-',
                $disk['usage_percent'] ?? '-',
                $disk['mount'] ?? '-',
            );
        }

        $lines[] = '';
        $lines[] = sprintf('## %s', (string) __('messages.network.interfaces_section'));
        $lines[] = '';
        $lines[] = sprintf(
            '| %s | %s | %s | %s | %s | %s |',
            (string) __('messages.network.interface_headers.interface'),
            (string) __('messages.network.interface_headers.ipv4'),
            (string) __('messages.network.interface_headers.gateway'),
            (string) __('messages.network.interface_headers.dns'),
            (string) __('messages.network.interface_headers.mac'),
            (string) __('messages.network.interface_headers.status'),
        );
        $lines[] = '| :--- | :--- | :--- | :--- | :--- | :--- |';

        foreach ((array) ($data['network_interfaces'] ?? []) as $iface) {
            if (! is_array($iface)) {
                continue;
            }
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $iface['interface'] ?? '-',
                $iface['ipv4'] ?? '-',
                $iface['gateway'] ?? '-',
                $iface['dns'] ?? '-',
                $iface['mac'] ?? '-',
                $iface['status'] ?? '-',
            );
        }

        $lines[] = '';
        $lines[] = sprintf('## %s', (string) __('messages.network.connectivity_section'));
        $lines[] = '';
        $lines[] = sprintf(
            '| %s | %s | %s | %s |',
            (string) __('messages.network.connectivity_headers.target'),
            (string) __('messages.network.connectivity_headers.check'),
            (string) __('messages.network.connectivity_headers.status'),
            (string) __('messages.network.connectivity_headers.latency'),
        );
        $lines[] = '| :--- | :--- | :--- | :--- |';

        foreach ((array) ($data['connectivity'] ?? []) as $check) {
            if (! is_array($check)) {
                continue;
            }
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $check['target'] ?? '-',
                $check['check'] ?? '-',
                $check['status'] ?? '-',
                $check['latency'] ?? '-',
            );
        }

        $lines[] = '';
        $lines[] = sprintf('## %s', (string) __('messages.printer.list_section'));
        $lines[] = '';

        $printers = (array) ($data['printers'] ?? []);
        if ($printers === []) {
            $lines[] = sprintf('_%s_', (string) __('messages.printer.no_printers'));
        } else {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                (string) __('messages.printer.headers.name'),
                (string) __('messages.printer.headers.port'),
                (string) __('messages.printer.headers.driver'),
                (string) __('messages.printer.headers.status'),
                (string) __('messages.printer.headers.jobs'),
                (string) __('messages.printer.headers.default'),
            );
            $lines[] = '| :--- | :--- | :--- | :--- | :--- | :--- |';

            foreach ($printers as $printer) {
                if (! is_array($printer)) {
                    continue;
                }
                $lines[] = sprintf(
                    '| %s | %s | %s | %s | %s | %s |',
                    $printer['name'] ?? '-',
                    $printer['port'] ?? '-',
                    $printer['driver'] ?? '-',
                    $printer['status'] ?? '-',
                    (string) ($printer['jobs'] ?? '0'),
                    $printer['default'] ?? '-',
                );
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Render the diagnostic payload into a formatted JSON string.
     *
     * @param  array<string, mixed>  $data
     */
    public function toJson(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
