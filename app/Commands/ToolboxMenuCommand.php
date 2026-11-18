<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\InteractsWithLocale;
use App\Commands\Concerns\InteractsWithTerminal;
use Laravel\Prompts\Prompt;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;
use Throwable;

use function Laravel\Prompts\error;
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
     * Return all submenu action definitions keyed by category slug.
     *
     * @return array<string, array<string, array{label_key: string, command: string}>>
     */
    public static function getSubmenuDefinitions(): array
    {
        return [
            'system' => [
                '1' => ['label_key' => 'messages.menu.actions.system_info', 'command' => 'system:info'],
                '2' => ['label_key' => 'messages.menu.actions.system_top', 'command' => 'system:top'],
                '3' => ['label_key' => 'messages.menu.actions.system_logs', 'command' => 'system:logs'],
                '4' => ['label_key' => 'messages.menu.actions.system_services', 'command' => 'system:services'],
                '5' => ['label_key' => 'messages.menu.actions.system_battery', 'command' => 'system:battery'],
                '6' => ['label_key' => 'messages.menu.actions.system_startup', 'command' => 'system:startup'],
                '7' => ['label_key' => 'messages.menu.actions.system_usb', 'command' => 'system:usb'],
                '8' => ['label_key' => 'messages.menu.actions.system_env', 'command' => 'system:env'],
                '9' => ['label_key' => 'messages.menu.actions.system_kill', 'command' => 'system:kill'],
            ],
            'network' => [
                '1' => ['label_key' => 'messages.menu.actions.network_check', 'command' => 'network:check'],
                '2' => ['label_key' => 'messages.menu.actions.network_flush', 'command' => 'network:flush'],
                '3' => ['label_key' => 'messages.menu.actions.network_ports', 'command' => 'network:ports'],
                '4' => ['label_key' => 'messages.menu.actions.network_probe', 'command' => 'network:probe'],
                '5' => ['label_key' => 'messages.menu.actions.network_wifi', 'command' => 'network:wifi'],
                '6' => ['label_key' => 'messages.menu.actions.network_reset', 'command' => 'network:reset'],
                '7' => ['label_key' => 'messages.menu.actions.network_ssl', 'command' => 'network:ssl'],
                '8' => ['label_key' => 'messages.menu.actions.network_scan', 'command' => 'network:scan'],
                '9' => ['label_key' => 'messages.menu.actions.network_public', 'command' => 'network:public'],
            ],
            'printers' => [
                '1' => ['label_key' => 'messages.menu.actions.printer_list', 'command' => 'printer:list'],
                '2' => ['label_key' => 'messages.menu.actions.printer_clear', 'command' => 'printer:clear'],
                '3' => ['label_key' => 'messages.menu.actions.printer_test_page', 'command' => 'printer:test-page'],
                '4' => ['label_key' => 'messages.menu.actions.printer_default', 'command' => 'printer:default'],
            ],
            'maintenance' => [
                '1' => ['label_key' => 'messages.menu.actions.clean_temp', 'command' => 'clean:temp'],
                '2' => ['label_key' => 'messages.menu.actions.maintenance_repair', 'command' => 'maintenance:repair'],
                '3' => ['label_key' => 'messages.menu.actions.maintenance_large_files', 'command' => 'maintenance:large-files'],
                '4' => ['label_key' => 'messages.menu.actions.maintenance_browsers', 'command' => 'maintenance:browsers'],
                '5' => ['label_key' => 'messages.menu.actions.maintenance_updates', 'command' => 'maintenance:updates'],
                '6' => ['label_key' => 'messages.menu.actions.maintenance_scheduler', 'command' => 'maintenance:scheduler'],
            ],
            'security' => [
                '1' => ['label_key' => 'messages.menu.actions.security_audit', 'command' => 'security:audit'],
                '2' => ['label_key' => 'messages.menu.actions.security_users', 'command' => 'security:users'],
                '3' => ['label_key' => 'messages.menu.actions.security_shares', 'command' => 'security:shares'],
                '4' => ['label_key' => 'messages.menu.actions.security_hosts', 'command' => 'security:hosts'],
            ],
            'reports' => [
                '1' => ['label_key' => 'messages.menu.actions.report_export', 'command' => 'report:export'],
                '2' => ['label_key' => 'messages.menu.actions.toolbox_benchmark', 'command' => 'toolbox:benchmark'],
                '3' => ['label_key' => 'messages.menu.actions.toolbox_inventory', 'command' => 'toolbox:inventory'],
            ],
        ];
    }

    /**
     * Return a flat, unique list of every subcommand signature referenced in the TUI menu.
     *
     * @return array<int, string>
     */
    public static function getAllMenuCommands(): array
    {
        $commands = ['health:check', 'self:update'];

        foreach (self::getSubmenuDefinitions() as $items) {
            foreach ($items as $entry) {
                $commands[] = $entry['command'];
            }
        }

        return array_values(array_unique($commands));
    }

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
                    '3' => (string) __('messages.menu.categories.network'),
                    '4' => (string) __('messages.menu.categories.printers'),
                    '5' => (string) __('messages.menu.categories.maintenance'),
                    '6' => (string) __('messages.menu.categories.security'),
                    '7' => (string) __('messages.menu.categories.reports'),
                    '8' => (string) __('messages.menu.categories.self_update'),
                    '9' => (string) __('messages.menu.categories.language'),
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
            } elseif ($choice === '8') {
                $this->executeSubcommand('self:update', $locale);
            } elseif ($choice === '9') {
                $locale = $this->promptNumericLanguageSwitch($locale);
            } else {
                $category = match ($choice) {
                    '2' => 'system',
                    '3' => 'network',
                    '4' => 'printers',
                    '5' => 'maintenance',
                    '6' => 'security',
                    '7' => 'reports',
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
        $submenus = self::getSubmenuDefinitions();

        while (true) {
            $this->clearScreen();
            $this->renderHeader($locale);

            $actionsMap = $submenus[$category] ?? [];

            $menuOptions = [];
            foreach ($actionsMap as $num => $item) {
                $menuOptions[$num] = (string) __($item['label_key']);
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
     * Clear the screen, restore normal terminal mode, execute a subcommand explicitly surfacing any errors,
     * and wait for user acknowledgment before returning to the menu.
     */
    protected function executeSubcommand(string $command, string $locale): void
    {
        $this->clearScreen();
        $this->renderHeader($locale);

        // Restore canonical/blocking TTY state before delegating to subcommands that read interactive Prompts input
        $this->restoreTerminal();
        Prompt::setOutput($this->output);

        try {
            $this->runCommand($command, ['--lang' => $locale], $this->output);
        } catch (Throwable $exception) {
            $this->restoreTerminal();
            $errorMsg = (string) __('messages.common.unexpected_error', ['error' => $exception->getMessage()]);
            $this->output->writeln('');
            $this->output->writeln(sprintf('<fg=red;options=bold>[%s] %s</>', $command, $errorMsg));
            error(sprintf('[%s] %s', $command, $exception->getMessage()));
        } finally {
            $this->restoreTerminal();
        }

        $this->pauseBeforeReturn();
    }

    /**
     * Display an explicit highlighted return prompt and pause before clearing the screen and returning to the menu.
     */
    protected function pauseBeforeReturn(): void
    {
        if (! isset($this->input) || ! $this->input->isInteractive()) {
            return;
        }

        $this->restoreTerminal();
        $this->drainInputBuffer();

        $prompt = (string) __('messages.menu.press_any_key');
        $this->output->writeln('');
        $this->output->write(sprintf('  <fg=yellow;options=bold>%s</> ', $prompt));

        $stdin = defined('STDIN') && is_resource(STDIN) ? STDIN : @fopen('php://stdin', 'rb');
        if (! is_resource($stdin)) {
            $this->output->writeln('');

            return;
        }

        if (PHP_OS_FAMILY !== 'Windows' && $this->isInteractiveTty() && Process::isTtySupported()) {
            if ($this->setUnixSttyMode(['-icanon', '-echo'])) {
                try {
                    @stream_set_blocking($stdin, true);
                    @fread($stdin, 1);
                } finally {
                    $this->restoreTerminal();
                }
                $this->output->writeln('');

                return;
            }
        }

        @stream_set_blocking($stdin, true);
        @fgets($stdin);
        $this->output->writeln('');
    }
}
