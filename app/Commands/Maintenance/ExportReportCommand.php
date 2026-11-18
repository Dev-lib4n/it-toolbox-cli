<?php

declare(strict_types=1);

namespace App\Commands\Maintenance;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

class ExportReportCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'report:export
                            {--format= : Export format (txt or json)}
                            {--output= : Destination file path}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Export a comprehensive system diagnostic report to TXT or JSON';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $locale = $this->resolveLocale();

        info((string) __('messages.report.section'));

        $format = $this->option('format');
        if (! is_string($format) || ! in_array(strtolower($format), ['txt', 'json'], true)) {
            $format = $this->input->isInteractive()
                ? (string) select(
                    label: (string) __('messages.report.format_prompt'),
                    options: [
                        'txt' => (string) __('messages.report.formats.txt'),
                        'json' => (string) __('messages.report.formats.json'),
                    ],
                    default: 'txt',
                )
                : 'txt';
        } else {
            $format = strtolower($format);
        }

        $reportData = spin(
            callback: fn (): array => [
                'generated_at' => gmdate('c'),
                'locale' => $locale,
                'os_family' => PHP_OS_FAMILY,
                'os_info' => $driver->getOsInfo(),
                'hardware' => $driver->getHardwareDiagnostics(),
                'storage' => $driver->getStorageHealth(),
                'top_processes' => $driver->getTopProcesses(10),
                'printers' => $driver->listPrinters(),
                'network_interfaces' => $driver->getNetworkInterfaces(),
                'connectivity' => $driver->runConnectivityHealthCheck(),
                'listening_ports' => $driver->getListeningPorts(),
            ],
            message: (string) __('messages.spinners.report_export'),
        );

        $customOutput = $this->option('output');
        $filePath = is_string($customOutput) && $customOutput !== ''
            ? $customOutput
            : (getcwd() ?: base_path()) . DIRECTORY_SEPARATOR . sprintf('it-toolbox-report-%s.%s', date('Ymd-His'), $format);

        $content = $format === 'json'
            ? (string) json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $this->formatPlainTextReport($reportData);

        $written = @file_put_contents($filePath, $content);

        if ($written === false) {
            error((string) __('messages.report.exported_failed', ['path' => $filePath]));

            return self::FAILURE;
        }

        info((string) __('messages.report.exported_success', ['path' => $filePath]));

        return self::SUCCESS;
    }

    /**
     * Render the diagnostic payload into a readable plain-text report.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatPlainTextReport(array $data): string
    {
        $lines = [
            '====================================================================',
            sprintf('%s - %s', (string) __('messages.app.name'), (string) __('messages.app.tagline')),
            sprintf('Generated At: %s | OS Family: %s', (string) $data['generated_at'], (string) $data['os_family']),
            '====================================================================',
            '',
            '[' . (string) __('messages.system.os_section') . ']',
        ];

        foreach ((array) $data['os_info'] as $key => $value) {
            $lines[] = sprintf('  %-20s : %s', (string) $key, (string) $value);
        }

        $lines[] = '';
        $lines[] = '[' . (string) __('messages.system.hardware_section') . ']';
        foreach ((array) $data['hardware'] as $key => $value) {
            $lines[] = sprintf('  %-20s : %s', (string) $key, (string) $value);
        }

        $lines[] = '';
        $lines[] = '[' . (string) __('messages.system.storage_section') . ']';
        foreach ((array) $data['storage'] as $disk) {
            $lines[] = sprintf(
                '  %s | Total: %s | Used: %s (%s) | Free: %s | Mount: %s',
                $disk['filesystem'] ?? '-',
                $disk['total'] ?? '-',
                $disk['used'] ?? '-',
                $disk['usage_percent'] ?? '-',
                $disk['available'] ?? '-',
                $disk['mount'] ?? '-'
            );
        }

        $lines[] = '';
        $lines[] = '[' . (string) __('messages.printer.list_section') . ']';
        foreach ((array) $data['printers'] as $printer) {
            $lines[] = sprintf(
                '  %s | Status: %s | Default: %s | Port: %s',
                $printer['name'] ?? '-',
                $printer['status'] ?? '-',
                $printer['default'] ?? '-',
                $printer['port'] ?? '-'
            );
        }

        $lines[] = '';
        $lines[] = '[' . (string) __('messages.network.interfaces_section') . ']';
        foreach ((array) $data['network_interfaces'] as $iface) {
            $lines[] = sprintf(
                '  %s | IPv4: %s | MAC: %s | Status: %s',
                $iface['interface'] ?? '-',
                $iface['ipv4'] ?? '-',
                $iface['mac'] ?? '-',
                $iface['status'] ?? '-'
            );
        }

        $lines[] = '';
        $lines[] = '[' . (string) __('messages.network.connectivity_section') . ']';
        foreach ((array) $data['connectivity'] as $check) {
            $lines[] = sprintf(
                '  %s (%s) -> %s [%s]',
                $check['target'] ?? '-',
                $check['check'] ?? '-',
                $check['status'] ?? '-',
                $check['latency'] ?? '-'
            );
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }
}
