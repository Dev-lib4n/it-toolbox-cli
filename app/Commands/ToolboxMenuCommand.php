<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\InteractsWithLocale;
use App\Commands\Concerns\InteractsWithTerminal;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class ToolboxMenuCommand extends Command
{
    use InteractsWithLocale;
    use InteractsWithTerminal;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'menu
                            {--lang= : Interface language (en or pt_BR)}
                            {--once : Execute a single menu selection and exit}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Launch the interactive IT Toolbox TUI dashboard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $locale = $this->resolveInitialLocale();

        while (true) {
            $this->clearScreen();
            $this->renderHeader($locale);

            $choice = $this->promptNumericMenu(
                promptLabel: (string) __('messages.menu.main_prompt'),
                options: [
                    '1' => (string) __('messages.menu.categories.health_check'),
                    '2' => (string) __('messages.menu.categories.system'),
                    '3' => (string) __('messages.menu.categories.printers'),
                    '4' => (string) __('messages.menu.categories.network'),
                    '5' => (string) __('messages.menu.categories.maintenance'),
                    '6' => (string) __('messages.menu.categories.reports'),
                    '7' => (string) __('messages.menu.categories.self_update'),
                    '8' => (string) __('messages.menu.categories.language'),
                    '0' => (string) __('messages.menu.exit'),
                ],
                defaultKey: '1',
            );

            if ($choice === '0') {
                $this->clearScreen();
                info((string) __('messages.app.goodbye'));

                return self::SUCCESS;
            }

            if ($choice === '1') {
                $this->executeSubcommand('health:check', $locale);
            } elseif ($choice === '6') {
                $this->executeSubcommand('report:export', $locale);
            } elseif ($choice === '7') {
                $this->executeSubcommand('self:update', $locale);
            } elseif ($choice === '8') {
                $locale = $this->promptNumericLanguageSwitch($locale);
            } else {
                $category = match ($choice) {
                    '2' => 'system',
                    '3' => 'printers',
                    '4' => 'network',
                    '5' => 'maintenance',
                    default => 'system',
                };

                $this->handleCategorySubmenu($category, $locale);
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }
        }
    }

    /**
     * Resolve the initial locale from --lang or display the numeric language selector.
     */
    protected function resolveInitialLocale(): string
    {
        $optionLocale = $this->option('lang');

        if (is_string($optionLocale) && $optionLocale !== '') {
            return $this->resolveLocale(promptIfMissing: false);
        }

        $this->clearScreen();
        $this->renderHeader('en');

        return $this->promptNumericLanguageSwitch('en', allowBack: false);
    }

    /**
     * Display a numbered language switcher ([1] English, [2] Português, and optionally [0] Back).
     */
    protected function promptNumericLanguageSwitch(string $currentLocale, bool $allowBack = true): string
    {
        if ($allowBack) {
            $this->clearScreen();
            $this->renderHeader($currentLocale);
        }

        $options = [
            '1' => (string) __('messages.language.en'),
            '2' => (string) __('messages.language.pt_BR'),
        ];

        if ($allowBack) {
            $options['0'] = (string) __('messages.menu.back');
        }

        $defaultKey = $currentLocale === 'pt_BR' ? '2' : '1';

        $choice = $this->promptNumericMenu(
            promptLabel: (string) __('messages.language.select_prompt'),
            options: $options,
            defaultKey: $defaultKey,
        );

        if ($choice === '0') {
            return $currentLocale;
        }

        $selectedLocale = $choice === '2' ? 'pt_BR' : 'en';
        $this->applyLocale($selectedLocale);

        return $selectedLocale;
    }

    /**
     * Display the numbered submenu for the chosen category in a loop until [0] Back is selected.
     */
    protected function handleCategorySubmenu(string $category, string $locale): void
    {
        while (true) {
            $this->clearScreen();
            $this->renderHeader($locale);

            /** @var array<string, array{label: string, command: string}> $actionsMap */
            $actionsMap = match ($category) {
                'system' => [
                    '1' => ['label' => (string) __('messages.menu.actions.system_info'), 'command' => 'system:info'],
                    '2' => ['label' => (string) __('messages.menu.actions.system_top'), 'command' => 'system:top'],
                    '3' => ['label' => (string) __('messages.menu.actions.system_logs'), 'command' => 'system:logs'],
                    '4' => ['label' => (string) __('messages.menu.actions.system_services'), 'command' => 'system:services'],
                    '5' => ['label' => (string) __('messages.menu.actions.system_battery'), 'command' => 'system:battery'],
                    '6' => ['label' => (string) __('messages.menu.actions.system_startup'), 'command' => 'system:startup'],
                    '7' => ['label' => (string) __('messages.menu.actions.system_usb'), 'command' => 'system:usb'],
                    '8' => ['label' => (string) __('messages.menu.actions.system_env'), 'command' => 'system:env'],
                    '9' => ['label' => (string) __('messages.menu.actions.system_kill'), 'command' => 'system:kill'],
                ],
                'printers' => [
                    '1' => ['label' => (string) __('messages.menu.actions.printer_list'), 'command' => 'printer:list'],
                    '2' => ['label' => (string) __('messages.menu.actions.printer_clear'), 'command' => 'printer:clear'],
                ],
                'network' => [
                    '1' => ['label' => (string) __('messages.menu.actions.network_check'), 'command' => 'network:check'],
                    '2' => ['label' => (string) __('messages.menu.actions.network_flush'), 'command' => 'network:flush'],
                    '3' => ['label' => (string) __('messages.menu.actions.network_ports'), 'command' => 'network:ports'],
                    '4' => ['label' => (string) __('messages.menu.actions.network_probe'), 'command' => 'network:probe'],
                    '5' => ['label' => (string) __('messages.menu.actions.network_wifi'), 'command' => 'network:wifi'],
                    '6' => ['label' => (string) __('messages.menu.actions.network_reset'), 'command' => 'network:reset'],
                    '7' => ['label' => (string) __('messages.menu.actions.network_ssl'), 'command' => 'network:ssl'],
                    '8' => ['label' => (string) __('messages.menu.actions.network_scan'), 'command' => 'network:scan'],
                    '9' => ['label' => (string) __('messages.menu.actions.network_public'), 'command' => 'network:public'],
                ],
                'maintenance' => [
                    '1' => ['label' => (string) __('messages.menu.actions.clean_temp'), 'command' => 'clean:temp'],
                ],
                default => [],
            };

            $menuOptions = [];
            foreach ($actionsMap as $num => $item) {
                $menuOptions[$num] = $item['label'];
            }
            $menuOptions['0'] = (string) __('messages.menu.back');

            $choice = $this->promptNumericMenu(
                promptLabel: (string) __('messages.menu.sub_prompt'),
                options: $menuOptions,
                defaultKey: '1',
            );

            if ($choice === '0' || ! isset($actionsMap[$choice])) {
                return;
            }

            $this->executeSubcommand($actionsMap[$choice]['command'], $locale);

            if ($this->option('once')) {
                return;
            }
        }
    }

    /**
     * Clear the screen, render the header, execute a subcommand, and wait for user acknowledgment.
     */
    protected function executeSubcommand(string $command, string $locale): void
    {
        $this->clearScreen();
        $this->renderHeader($locale);
        $this->call($command, ['--lang' => $locale]);
        $this->waitForKeyPress();
    }
}
