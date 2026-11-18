<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Services\SelfUpdateService;
use LaravelZero\Framework\Commands\Command;
use Phar;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class SelfUpdateCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'self:update
                            {--check : Only check if a new version is available without downloading}
                            {--no-restart : Do not automatically relaunch the updated executable}
                            {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Check for newer versions on GitHub Releases and self-update the executable';

    /**
     * Execute the console command.
     */
    public function handle(SelfUpdateService $updateService): int
    {
        $locale = $this->resolveLocale();

        info((string) __('messages.update.section'));

        $check = spin(
            callback: fn (): array => $updateService->checkForUpdates(),
            message: (string) __('messages.spinners.self_update_check'),
        );

        if (! $check['success']) {
            error((string) __('messages.update.check_failed'));

            return self::FAILURE;
        }

        if (! $check['update_available']) {
            info((string) __('messages.update.up_to_date', [
                'version' => $check['current_version'],
            ]));

            return self::SUCCESS;
        }

        info((string) __('messages.update.available', [
            'current' => $check['current_version'],
            'latest' => $check['latest_version'],
        ]));

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        if ($check['asset_name'] === null) {
            warning((string) __('messages.update.source_mode_warning'));

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! confirm(
            label: (string) __('messages.update.confirm_download', [
                'version' => $check['latest_version'],
                'asset' => $check['asset_name'],
            ]),
            default: true,
            yes: (string) __('messages.common.yes'),
            no: (string) __('messages.common.no'),
        )) {
            warning((string) __('messages.common.cancelled'));

            return self::SUCCESS;
        }

        // Pre-resolve post-update strings before performUpdate() replaces the PHAR on disk,
        // preventing lazy includes/translation reads against invalidated PHAR offsets.
        $updatedSuccessMsg = (string) __('messages.update.updated_success');
        $restartNoticeMsg = (string) __('messages.update.restart_notice', [
            'version' => $check['latest_version'],
        ]);
        $confirmRestartLabel = (string) __('messages.update.confirm_restart', [
            'version' => $check['latest_version'],
        ]);
        $restartingNewVersionMsg = (string) __('messages.update.restarting_new_version', [
            'version' => $check['latest_version'],
        ]);
        $yesLabel = (string) __('messages.common.yes');
        $noLabel = (string) __('messages.common.no');
        $downloadFailedMsg = (string) __('messages.update.download_failed');
        $versionSummary = sprintf(
            '  v%s -> v%s (%s)',
            $check['current_version'],
            $check['latest_version'],
            (string) $check['asset_name'],
        );

        try {
            $result = spin(
                callback: fn () => $updateService->performUpdate(),
                message: (string) __('messages.spinners.self_update_download', [
                    'version' => $check['latest_version'],
                ]),
            );
        } catch (RuntimeException $exception) {
            error($downloadFailedMsg . ' ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($result->success && $result->message === 'messages.update.updated_success') {
            info($updatedSuccessMsg);
            $this->output->writeln($versionSummary);
            if (isset($result->details['paths']) && is_array($result->details['paths'])) {
                foreach ($result->details['paths'] as $updatedPath) {
                    $this->output->writeln('  ' . (string) $updatedPath);
                }
            }

            $runningBinary = $this->resolveRunningBinaryPath();
            $noRestart = (bool) $this->option('no-restart');

            if ($runningBinary !== '' && ! $noRestart) {
                $shouldRestart = true;
                if ($this->input->isInteractive()) {
                    $shouldRestart = confirm(
                        label: $confirmRestartLabel,
                        default: true,
                        yes: $yesLabel,
                        no: $noLabel,
                    );
                }

                if ($shouldRestart) {
                    info($restartingNewVersionMsg);
                    $this->relaunchBinary($runningBinary, $locale);
                }
            }

            warning($restartNoticeMsg);

            if (Phar::running(false) !== '') {
                // Exit immediately so no post-command menu loop or autoload triggers
                // `include(): zlib: data error` against the overwritten PHAR file.
                exit(0);
            }

            return self::SUCCESS;
        }

        return $this->renderActionResult($result);
    }

    /**
     * Resolve the running PHAR / standalone binary path (empty when running from unpacked source).
     */
    protected function resolveRunningBinaryPath(): string
    {
        return Phar::running(false);
    }

    /**
     * Relaunch the newly replaced executable with the active locale and terminate this old process.
     */
    protected function relaunchBinary(string $binaryPath, string $locale): never
    {
        sleep(1);

        $safeLocale = in_array($locale, $this->supportedLocales, true) ? $locale : 'en';
        $langFlag = '--lang=' . $safeLocale;

        if (PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_exec')) {
            pcntl_exec($binaryPath, [$langFlag]);
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? escapeshellarg($binaryPath) . ' ' . $langFlag
            : escapeshellarg($binaryPath) . ' --lang=' . escapeshellarg($safeLocale);

        $exitCode = 0;
        passthru($command, $exitCode);

        exit((int) $exitCode);
    }
}
