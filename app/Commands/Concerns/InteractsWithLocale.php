<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\DTOs\ActionResult;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

trait InteractsWithLocale
{
    /**
     * Supported application locales.
     *
     * @var array<int, string>
     */
    protected array $supportedLocales = ['en', 'pt_BR'];

    /**
     * Resolve and apply the active locale from the --lang option.
     *
     * @param  bool  $promptIfMissing  Whether to prompt interactively if --lang was not provided.
     * @return string The resolved locale code ('en' or 'pt_BR').
     */
    protected function resolveLocale(bool $promptIfMissing = false): string
    {
        $optionLocale = $this->option('lang');

        if (is_string($optionLocale) && $optionLocale !== '') {
            if (! in_array($optionLocale, $this->supportedLocales, true)) {
                $this->applyLocale('en');
                warning((string) __('messages.language.invalid', ['lang' => $optionLocale]));

                return 'en';
            }

            $this->applyLocale($optionLocale);

            return $optionLocale;
        }

        if ($promptIfMissing) {
            return $this->promptForLanguage();
        }

        $current = app()->getLocale() ?: 'en';
        $resolved = in_array($current, $this->supportedLocales, true) ? $current : 'en';
        $this->applyLocale($resolved);

        return $resolved;
    }

    /**
     * Prompt the user interactively to choose between English and Brazilian Portuguese.
     */
    protected function promptForLanguage(): string
    {
        $selected = (string) select(
            label: (string) __('messages.language.select_prompt'),
            options: [
                'en' => (string) __('messages.language.en'),
                'pt_BR' => (string) __('messages.language.pt_BR'),
            ],
            default: in_array(app()->getLocale(), $this->supportedLocales, true) ? app()->getLocale() : 'en',
        );

        $this->applyLocale($selected);
        info((string) __('messages.language.switched'));

        return $selected;
    }

    /**
     * Apply the given locale to the application and translator instances.
     */
    protected function applyLocale(string $locale): void
    {
        app()->setLocale($locale);

        if (app()->bound('translator')) {
            app('translator')->setLocale($locale);
        }
    }

    /**
     * Render an ActionResult DTO using Laravel Prompts components.
     */
    protected function renderActionResult(ActionResult $result): int
    {
        $translatedMessage = (string) __($result->message);

        if ($result->success) {
            info($translatedMessage);
        } else {
            error($translatedMessage);
        }

        if ($result->details !== []) {
            $rows = [];
            foreach ($result->details as $key => $value) {
                $labelKey = "messages.maintenance.details_labels.{$key}";
                $label = (string) __($labelKey);
                if ($label === $labelKey) {
                    $label = ucfirst(str_replace('_', ' ', (string) $key));
                }

                $formattedValue = is_array($value)
                    ? implode(', ', array_map('strval', $value))
                    : (string) $value;

                $rows[] = [$label, $formattedValue];
            }

            table(
                headers: [
                    (string) __('messages.common.metric'),
                    (string) __('messages.common.value'),
                ],
                rows: $rows,
            );
        }

        return $result->success ? self::SUCCESS : self::FAILURE;
    }
}
