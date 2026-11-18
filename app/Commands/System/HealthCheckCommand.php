<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Services\HealthCheckService;
use App\Services\ReportExporterService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class HealthCheckCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'health:check
                            {--export : Automatically export the full diagnostic report in MD and JSON}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Run a comprehensive system health check with a 0-100 health score and action recommendations';

    /**
     * Execute the console command.
     */
    public function handle(HealthCheckService $healthService, ReportExporterService $exporter): int
    {
        $locale = $this->resolveLocale();

        $audit = spin(
            callback: fn (): array => $healthService->runAudit(),
            message: (string) __('messages.spinners.health_check'),
        );

        info((string) __('messages.health.section'));

        $scoreBanner = sprintf(
            '%s: %s  (%s)',
            (string) __('messages.health.score_label'),
            $audit['progress_bar'],
            $audit['state_label']
        );

        match ($audit['state']) {
            'healthy' => info($scoreBanner),
            'warning' => warning($scoreBanner),
            default => error($scoreBanner),
        };

        table(
            headers: [
                (string) __('messages.health.headers.category'),
                (string) __('messages.health.headers.status'),
                (string) __('messages.health.headers.summary'),
                (string) __('messages.health.headers.impact'),
            ],
            rows: array_map(
                fn (array $item): array => [
                    $item['category'],
                    sprintf('%s %s', $item['icon'], $item['status']),
                    $item['summary'],
                    $item['penalty'] > 0 ? sprintf('-%d pts', $item['penalty']) : '0 pts',
                ],
                $audit['checks']
            ),
        );

        if ($audit['recommendations'] !== []) {
            warning((string) __('messages.health.recommendations_title'));
            $recLines = array_map(
                fn (string $rec): string => sprintf('  • %s', $rec),
                $audit['recommendations']
            );
            note(implode(PHP_EOL, $recLines));
        } else {
            info((string) __('messages.health.no_recommendations'));
        }

        $shouldExport = (bool) $this->option('export');

        if (! $shouldExport && $this->input->isInteractive()) {
            $shouldExport = confirm(
                label: (string) __('messages.health.ask_export'),
                default: false,
                yes: (string) __('messages.common.yes'),
                no: (string) __('messages.common.no'),
            );
        }

        if ($shouldExport) {
            $exportResult = spin(
                callback: fn () => $exporter->export(
                    format: 'both',
                    outputDirectory: null,
                    locale: $locale,
                    timestamp: null,
                    healthAudit: $audit,
                ),
                message: (string) __('messages.spinners.report_export'),
            );

            $paths = isset($exportResult->details['paths']) && is_array($exportResult->details['paths'])
                ? implode(', ', array_map('strval', $exportResult->details['paths']))
                : '';

            if ($exportResult->success) {
                info((string) __('messages.report.exported_success', ['path' => $paths]));
            } else {
                error((string) __('messages.report.exported_failed', ['path' => $paths]));
            }
        }

        return self::SUCCESS;
    }
}
