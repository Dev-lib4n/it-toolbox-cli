<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Services\SelfUpdateService;
use LaravelZero\Framework\Commands\Command;

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
        $this->resolveLocale();

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

        $result = spin(
            callback: fn () => $updateService->performUpdate(),
            message: (string) __('messages.spinners.self_update_download', [
                'version' => $check['latest_version'],
            ]),
        );

        return $this->renderActionResult($result);
    }
}
