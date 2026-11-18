<?php

declare(strict_types=1);

namespace App\Commands\Maintenance;

use App\Commands\Concerns\InteractsWithLocale;
use App\Services\ReportExporterService;
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
                            {--format= : Export format (md, json, or both)}
                            {--output= : Destination directory path}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Export a comprehensive system diagnostic report to Markdown (.md) and/or JSON (.json)';

    /**
     * Execute the console command.
     */
    public function handle(ReportExporterService $exporter): int
    {
        $locale = $this->resolveLocale();

        info((string) __('messages.report.section'));

        $format = $this->option('format');
        if (! is_string($format) || ! in_array(strtolower($format), ['md', 'markdown', 'json', 'both'], true)) {
            $format = $this->input->isInteractive()
                ? (string) select(
                    label: (string) __('messages.report.format_prompt'),
                    options: [
                        'both' => (string) __('messages.report.formats.both'),
                        'md' => (string) __('messages.report.formats.md'),
                        'json' => (string) __('messages.report.formats.json'),
                    ],
                    default: 'both',
                )
                : 'both';
        }

        $outputDir = $this->option('output');
        $targetDir = is_string($outputDir) && $outputDir !== '' ? $outputDir : null;

        $result = spin(
            callback: fn () => $exporter->export(
                format: $format,
                outputDirectory: $targetDir,
                locale: $locale,
            ),
            message: (string) __('messages.spinners.report_export'),
        );

        $paths = isset($result->details['paths']) && is_array($result->details['paths'])
            ? implode(', ', array_map('strval', $result->details['paths']))
            : '';

        if (! $result->success) {
            error((string) __('messages.report.exported_failed', ['path' => $paths]));

            return self::FAILURE;
        }

        info((string) __('messages.report.exported_success', ['path' => $paths]));

        return self::SUCCESS;
    }
}
