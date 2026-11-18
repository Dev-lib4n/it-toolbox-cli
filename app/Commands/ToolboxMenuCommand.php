<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\InteractsWithLocale;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

class ToolboxMenuCommand extends Command
{
    use InteractsWithLocale;

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
        $locale = $this->resolveLocale(promptIfMissing: true);

        $this->renderHeader();

        while (true) {
            $category = (string) select(
                label: (string) __('messages.menu.main_prompt'),
                options: [
                    'system' => (string) __('messages.menu.categories.system'),
                    'printers' => (string) __('messages.menu.categories.printers'),
                    'network' => (string) __('messages.menu.categories.network'),
                    'maintenance' => (string) __('messages.menu.categories.maintenance'),
                    'reports' => (string) __('messages.menu.categories.reports'),
                    'language' => (string) __('messages.menu.categories.language'),
                    'exit' => (string) __('messages.menu.exit'),
                ],
            );

            if ($category === 'exit') {
                info((string) __('messages.app.goodbye'));

                return self::SUCCESS;
            }

            if ($category === 'language') {
                $locale = $this->promptForLanguage();
                $this->renderHeader();
            } else {
                $this->handleCategorySubmenu($category, $locale);
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }
        }
    }

    /**
     * Render the TUI header banner.
     */
    protected function renderHeader(): void
    {
        info(sprintf('%s — %s', (string) __('messages.app.name'), (string) __('messages.app.tagline')));
        note((string) __('messages.app.detected_os', ['os' => PHP_OS_FAMILY]));
    }

    /**
     * Display the submenu for the chosen category and dispatch the selected command.
     */
    protected function handleCategorySubmenu(string $category, string $locale): void
    {
        $options = match ($category) {
            'system' => [
                'system:info' => (string) __('messages.menu.actions.system_info'),
                'system:top' => (string) __('messages.menu.actions.system_top'),
                'back' => (string) __('messages.menu.back'),
            ],
            'printers' => [
                'printer:list' => (string) __('messages.menu.actions.printer_list'),
                'printer:clear' => (string) __('messages.menu.actions.printer_clear'),
                'back' => (string) __('messages.menu.back'),
            ],
            'network' => [
                'network:check' => (string) __('messages.menu.actions.network_check'),
                'network:flush' => (string) __('messages.menu.actions.network_flush'),
                'network:ports' => (string) __('messages.menu.actions.network_ports'),
                'back' => (string) __('messages.menu.back'),
            ],
            'maintenance' => [
                'clean:temp' => (string) __('messages.menu.actions.clean_temp'),
                'back' => (string) __('messages.menu.back'),
            ],
            'reports' => [
                'report:export' => (string) __('messages.menu.actions.report_export'),
                'back' => (string) __('messages.menu.back'),
            ],
            default => [
                'back' => (string) __('messages.menu.back'),
            ],
        };

        $action = (string) select(
            label: (string) __('messages.menu.sub_prompt'),
            options: $options,
        );

        if ($action === 'back') {
            return;
        }

        $this->call($action, ['--lang' => $locale]);
    }
}
