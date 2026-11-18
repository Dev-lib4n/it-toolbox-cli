<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class InventoryCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'toolbox:inventory
                            {--export : Export asset inventory sheet to Markdown (.md) and JSON (.json)}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Generate a complete hardware & IT asset inventory profile ready for GLPI / CMDB';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $locale = $this->resolveLocale();

        info((string) __('messages.toolbox.inventory_section'));

        $inventory = spin(
            callback: fn (): array => $driver->getHardwareInventory(),
            message: (string) __('messages.spinners.toolbox_inventory'),
        );

        info((string) __('messages.toolbox.identity_subsection'));
        table(
            headers: [
                (string) __('messages.common.metric'),
                (string) __('messages.common.value'),
            ],
            rows: $this->formatMapRows($inventory['system_identity']),
        );

        info((string) __('messages.toolbox.cpu_ram_subsection'));
        table(
            headers: [
                (string) __('messages.common.metric'),
                (string) __('messages.common.value'),
            ],
            rows: $this->formatMapRows($inventory['processor_memory']),
        );

        info((string) __('messages.toolbox.disks_subsection'));
        table(
            headers: [
                (string) __('messages.toolbox.disk_headers.model'),
                (string) __('messages.toolbox.disk_headers.size'),
                (string) __('messages.toolbox.disk_headers.media_type'),
                (string) __('messages.toolbox.disk_headers.bus_type'),
            ],
            rows: array_map(
                fn (array $disk): array => [
                    $disk['model'] ?? '-',
                    $disk['size'] ?? '-',
                    $disk['media_type'] ?? '-',
                    $disk['bus_type'] ?? '-',
                ],
                $inventory['physical_disks']
            ),
        );

        info((string) __('messages.toolbox.network_subsection'));
        table(
            headers: [
                (string) __('messages.network.interface_headers.interface'),
                (string) __('messages.network.interface_headers.ipv4'),
                (string) __('messages.network.interface_headers.mac'),
                (string) __('messages.network.interface_headers.status'),
            ],
            rows: array_map(
                fn (array $iface): array => [
                    $iface['interface'] ?? '-',
                    $iface['ipv4'] ?? '-',
                    $iface['mac'] ?? '-',
                    $iface['status'] ?? '-',
                ],
                $inventory['network_adapters']
            ),
        );

        $shouldExport = (bool) $this->option('export');
        if (! $shouldExport && $this->input->isInteractive()) {
            $shouldExport = confirm(
                label: (string) __('messages.toolbox.ask_export_inventory'),
                default: false,
                yes: (string) __('messages.common.yes'),
                no: (string) __('messages.common.no'),
            );
        }

        if ($shouldExport) {
            return $this->exportInventorySheet($inventory, $locale);
        }

        return self::SUCCESS;
    }

    /**
     * Map associative inventory fields into translated metric/value rows.
     *
     * @param  array<string, string>  $fields
     * @return array<int, array{0: string, 1: string}>
     */
    protected function formatMapRows(array $fields): array
    {
        $rows = [];
        foreach ($fields as $key => $val) {
            $labelKey = "messages.toolbox.fields.{$key}";
            $label = (string) __($labelKey);
            if ($label === $labelKey) {
                $label = ucfirst(str_replace('_', ' ', $key));
            }
            $rows[] = [$label, (string) $val];
        }

        return $rows;
    }

    /**
     * Export the asset inventory profile into Markdown (.md) and JSON (.json) files.
     *
     * @param  array<string, mixed>  $inventory
     */
    protected function exportInventorySheet(array $inventory, string $locale): int
    {
        $dir = rtrim(getcwd() ?: base_path(), '/\\');
        $stamp = date('Y-m-d-His');
        $baseName = sprintf('it-toolbox-inventory-%s', $stamp);
        $mdPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.md';
        $jsonPath = $dir . DIRECTORY_SEPARATOR . $baseName . '.json';

        $payload = [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'locale' => $locale,
            'os_family' => PHP_OS_FAMILY,
            'inventory' => $inventory,
        ];

        $mdLines = [
            sprintf('# %s — %s', (string) __('messages.app.name'), (string) __('messages.toolbox.inventory_section')),
            '',
            sprintf('## %s', (string) __('messages.toolbox.identity_subsection')),
            '',
            sprintf('| %s | %s |', (string) __('messages.common.metric'), (string) __('messages.common.value')),
            '| :--- | :--- |',
        ];

        foreach ($this->formatMapRows((array) ($inventory['system_identity'] ?? [])) as [$k, $v]) {
            $mdLines[] = sprintf('| %s | %s |', $k, $v);
        }

        $mdLines[] = '';
        $mdLines[] = sprintf('## %s', (string) __('messages.toolbox.cpu_ram_subsection'));
        $mdLines[] = '';
        $mdLines[] = sprintf('| %s | %s |', (string) __('messages.common.metric'), (string) __('messages.common.value'));
        $mdLines[] = '| :--- | :--- |';

        foreach ($this->formatMapRows((array) ($inventory['processor_memory'] ?? [])) as [$k, $v]) {
            $mdLines[] = sprintf('| %s | %s |', $k, $v);
        }

        $mdLines[] = '';

        $mdOk = @file_put_contents($mdPath, implode("\n", $mdLines)) !== false;
        $jsonOk = @file_put_contents($jsonPath, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;

        if (! $mdOk || ! $jsonOk) {
            error((string) __('messages.report.exported_failed', ['path' => $mdPath]));

            return self::FAILURE;
        }

        info((string) __('messages.report.exported_success', ['path' => $mdPath . ', ' . $jsonPath]));

        return self::SUCCESS;
    }
}
