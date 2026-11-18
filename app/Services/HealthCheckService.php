<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SystemDriverInterface;

class HealthCheckService
{
    public function __construct(
        private readonly SystemDriverInterface $driver,
    ) {}

    /**
     * Run a comprehensive machine health audit and calculate a 0-100 health score.
     *
     * @return array{
     *     score: int,
     *     state: string,
     *     state_label: string,
     *     progress_bar: string,
     *     checks: array<int, array{category: string, icon: string, status: string, summary: string, penalty: int}>,
     *     recommendations: array<int, string>
     * }
     */
    public function runAudit(): array
    {
        $score = 100;
        $checks = [];
        $recommendations = [];

        $hardware = $this->driver->getHardwareDiagnostics();
        $storage = $this->driver->getStorageHealth();
        $connectivity = $this->driver->runConnectivityHealthCheck();
        $printers = $this->driver->listPrinters();
        $logsResult = $this->driver->getCriticalLogs(10);

        // 1. RAM Usage Audit
        $ramPercent = $this->extractPercentage($hardware['ram_used'] ?? '0%');
        $ramPenalty = 0;
        $ramIcon = '✅';
        $ramStatus = (string) __('messages.health.states.healthy');

        if ($ramPercent > 95.0) {
            $ramPenalty = 25;
            $ramIcon = '❌';
            $ramStatus = (string) __('messages.health.states.critical');
            $recommendations[] = (string) __('messages.health.recommendations.high_ram');
        } elseif ($ramPercent > 85.0) {
            $ramPenalty = 15;
            $ramIcon = '⚠️';
            $ramStatus = (string) __('messages.health.states.warning');
            $recommendations[] = (string) __('messages.health.recommendations.high_ram');
        }

        $score -= $ramPenalty;
        $checks[] = [
            'category' => (string) __('messages.health.categories.ram'),
            'icon' => $ramIcon,
            'status' => $ramStatus,
            'summary' => (string) ($hardware['ram_used'] ?? 'N/A'),
            'penalty' => $ramPenalty,
        ];

        // 2. Storage (Disks) Audit
        $diskPenalty = 0;
        $maxDiskPercent = 0.0;
        $diskSummaries = [];

        foreach ($storage as $disk) {
            $pct = $this->extractPercentage($disk['usage_percent'] ?? '0%');
            $maxDiskPercent = max($maxDiskPercent, $pct);
            $diskSummaries[] = sprintf('%s: %.1f%%', $disk['mount'] ?? ($disk['filesystem'] ?? 'Disk'), $pct);

            if ($pct > 95.0) {
                $diskPenalty += 25;
            } elseif ($pct > 85.0) {
                $diskPenalty += 15;
            }
        }

        $diskIcon = '✅';
        $diskStatus = (string) __('messages.health.states.healthy');
        if ($maxDiskPercent > 95.0) {
            $diskIcon = '❌';
            $diskStatus = (string) __('messages.health.states.critical');
            $recommendations[] = (string) __('messages.health.recommendations.high_disk');
        } elseif ($maxDiskPercent > 85.0) {
            $diskIcon = '⚠️';
            $diskStatus = (string) __('messages.health.states.warning');
            $recommendations[] = (string) __('messages.health.recommendations.high_disk');
        }

        $score -= $diskPenalty;
        $checks[] = [
            'category' => (string) __('messages.health.categories.storage'),
            'icon' => $diskIcon,
            'status' => $diskStatus,
            'summary' => $diskSummaries !== [] ? implode(', ', $diskSummaries) : 'N/A',
            'penalty' => $diskPenalty,
        ];

        // 3. Connectivity & DNS Audit
        $netPenalty = 0;
        $internetOk = true;
        $dnsOk = true;

        if ($connectivity === []) {
            $internetOk = false;
            $dnsOk = false;
        } else {
            foreach ($connectivity as $step) {
                $checkType = strtolower((string) ($step['check'] ?? ''));
                $target = strtolower((string) ($step['target'] ?? ''));
                $isStepOk = strtoupper((string) ($step['status'] ?? 'FAIL')) === 'OK';

                if (str_contains($checkType, 'dns') || str_contains($target, 'google.com') || str_contains($target, 'cloudflare.com')) {
                    if (! $isStepOk) {
                        $dnsOk = false;
                    }
                } elseif (str_contains($checkType, 'external') || str_contains($target, '1.1.1.1') || str_contains($target, '8.8.8.8')) {
                    if (! $isStepOk) {
                        $internetOk = false;
                    }
                }
            }
        }

        if (! $internetOk) {
            $netPenalty += 25;
            $recommendations[] = (string) __('messages.health.recommendations.no_internet');
        }

        if (! $dnsOk) {
            $netPenalty += 15;
            $recommendations[] = (string) __('messages.health.recommendations.dns_failed');
        }

        $netIcon = '✅';
        $netStatus = (string) __('messages.health.states.healthy');
        if (! $internetOk) {
            $netIcon = '❌';
            $netStatus = (string) __('messages.health.states.critical');
        } elseif (! $dnsOk) {
            $netIcon = '⚠️';
            $netStatus = (string) __('messages.health.states.warning');
        }

        $score -= $netPenalty;
        $checks[] = [
            'category' => (string) __('messages.health.categories.network'),
            'icon' => $netIcon,
            'status' => $netStatus,
            'summary' => sprintf(
                'Internet: %s | DNS: %s',
                $internetOk ? 'OK' : 'FAIL',
                $dnsOk ? 'OK' : 'FAIL'
            ),
            'penalty' => $netPenalty,
        ];

        // 4. Print Queue & Spooler Audit
        $printerPenalty = 0;
        $printerIcon = '✅';
        $printerStatus = (string) __('messages.health.states.healthy');
        $printerSummary = (string) __('messages.printer.no_printers');

        if ($printers !== []) {
            $hasIssue = false;
            $totalJobs = 0;

            foreach ($printers as $printer) {
                $jobs = (int) ($printer['jobs'] ?? 0);
                $totalJobs += $jobs;
                $statusLower = strtolower((string) ($printer['status'] ?? ''));

                if ($jobs > 0
                    || str_contains($statusLower, 'error')
                    || str_contains($statusLower, 'jam')
                    || str_contains($statusLower, 'offline')
                    || str_contains($statusLower, 'paused')
                    || str_contains($statusLower, 'disabled')
                    || str_contains($statusLower, 'stopped')) {
                    $hasIssue = true;
                }
            }

            $printerSummary = sprintf('%d printers (%d pending jobs)', count($printers), $totalJobs);

            if ($hasIssue) {
                $printerPenalty = 10;
                $printerIcon = '⚠️';
                $printerStatus = (string) __('messages.health.states.warning');
                $recommendations[] = (string) __('messages.health.recommendations.printer_issue');
            }
        }

        $score -= $printerPenalty;
        $checks[] = [
            'category' => (string) __('messages.health.categories.printers'),
            'icon' => $printerIcon,
            'status' => $printerStatus,
            'summary' => $printerSummary,
            'penalty' => $printerPenalty,
        ];

        // 5. Critical OS Logs (last 24h) Audit
        $logEvents = isset($logsResult->details['events']) && is_array($logsResult->details['events'])
            ? $logsResult->details['events']
            : [];
        $logCount = count($logEvents);
        $logPenalty = 0;
        $logIcon = '✅';
        $logStatus = (string) __('messages.health.states.healthy');

        if ($logCount > 5) {
            $logPenalty = 15;
            $logIcon = '❌';
            $logStatus = (string) __('messages.health.states.critical');
            $recommendations[] = (string) __('messages.health.recommendations.critical_logs');
        } elseif ($logCount >= 1) {
            $logPenalty = 5;
            $logIcon = '⚠️';
            $logStatus = (string) __('messages.health.states.warning');
            $recommendations[] = (string) __('messages.health.recommendations.critical_logs');
        }

        $score -= $logPenalty;
        $checks[] = [
            'category' => (string) __('messages.health.categories.logs'),
            'icon' => $logIcon,
            'status' => $logStatus,
            'summary' => (string) __('messages.health.logs_count_summary', ['count' => $logCount]),
            'penalty' => $logPenalty,
        ];

        $finalScore = max(0, min(100, $score));
        $state = $this->classifyScore($finalScore);
        $stateLabel = (string) __("messages.health.states.{$state}");

        return [
            'score' => $finalScore,
            'state' => $state,
            'state_label' => $stateLabel,
            'progress_bar' => $this->buildProgressBar($finalScore),
            'checks' => $checks,
            'recommendations' => array_values(array_unique($recommendations)),
        ];
    }

    /**
     * Classify a 0-100 health score into 'healthy' (80-100), 'warning' (50-79), or 'critical' (0-49).
     */
    public function classifyScore(int $score): string
    {
        if ($score >= 80) {
            return 'healthy';
        }

        if ($score >= 50) {
            return 'warning';
        }

        return 'critical';
    }

    /**
     * Extract a float percentage from a formatted string such as "12.40 GB (88.5%)" or "⚠️ 90.0% [HIGH]".
     */
    protected function extractPercentage(string $value): float
    {
        if (preg_match('/([\d.]+)\s*%/', $value, $matches) === 1) {
            return (float) $matches[1];
        }

        return 0.0;
    }

    /**
     * Build a 20-block visual progress bar for the 0-100 score.
     */
    protected function buildProgressBar(int $score): string
    {
        $totalBlocks = 20;
        $filledBlocks = (int) round(($score / 100) * $totalBlocks);
        $emptyBlocks = max(0, $totalBlocks - $filledBlocks);

        return sprintf(
            '[%s%s] %d/100',
            str_repeat('█', $filledBlocks),
            str_repeat('░', $emptyBlocks),
            $score
        );
    }
}
