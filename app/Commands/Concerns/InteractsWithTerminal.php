<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Contracts\ProcessExecutorInterface;
use Symfony\Component\Process\Process;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

trait InteractsWithTerminal
{
    /**
     * Cached privilege check result for the current CLI session.
     */
    protected ?bool $cachedIsElevated = null;

    /**
     * Determine whether STDIN and STDOUT are attached to an interactive TTY terminal.
     */
    protected function isInteractiveTty(): bool
    {
        if (! isset($this->input) || ! $this->input->isInteractive()) {
            return false;
        }

        if (! defined('STDIN') || ! defined('STDOUT')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN) && @stream_isatty(STDOUT);
        }

        return false;
    }

    /**
     * Clear the terminal viewport and reposition the cursor at the top-left corner.
     */
    protected function clearScreen(): void
    {
        if (! $this->isInteractiveTty()) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_vt100_support')) {
            @sapi_windows_vt100_support(STDOUT, true);
        }

        fwrite(STDOUT, "\033[2J\033[H\033[3J");
        fflush(STDOUT);
    }

    /**
     * Render the compact top banner showing tool name, detected OS, privilege level, and active locale.
     */
    protected function renderHeader(?string $locale = null): void
    {
        $activeLocale = $locale ?? (app()->getLocale() ?: 'en');
        $langBadge = $activeLocale === 'pt_BR' ? 'PT-BR' : 'EN';
        $privilegeLabel = $this->isElevatedPrivilege()
            ? (string) __('messages.app.privilege_admin')
            : (string) __('messages.app.privilege_user');

        info(sprintf(
            '=== %s — %s ===',
            strtoupper((string) __('messages.app.name')),
            (string) __('messages.app.tagline')
        ));

        note((string) __('messages.app.header_status', [
            'os' => PHP_OS_FAMILY,
            'privilege' => $privilegeLabel,
            'lang' => $langBadge,
        ]));
    }

    /**
     * Display a numbered menu ([1]..[N], [0]) and capture the user's numeric selection.
     *
     * @param  string  $promptLabel
     * @param  array<string, string>  $options  Map of numeric key ('1', '2', ..., '0') to translated label.
     * @param  string  $defaultKey
     * @return string The selected key from $options.
     */
    protected function promptNumericMenu(string $promptLabel, array $options, string $defaultKey = '1'): string
    {
        $lines = [];
        foreach ($options as $key => $label) {
            $lines[] = sprintf('  [%s] %s', $key, $label);
        }

        note($promptLabel . PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines));

        $validKeys = array_map('strval', array_keys($options));
        $optionsList = implode(', ', $validKeys);

        if (PHP_OS_FAMILY !== 'Windows' && $this->isInteractiveTty() && Process::isTtySupported()) {
            $singleKey = $this->readSingleKeyUnix($validKeys, $optionsList);
            if ($singleKey !== null) {
                return $singleKey;
            }
        }

        $selected = text(
            label: (string) __('messages.menu.numeric_hint', ['options' => $optionsList]),
            default: in_array($defaultKey, $validKeys, true) ? $defaultKey : ($validKeys[0] ?? '0'),
            validate: fn (string $value): ?string => in_array(trim($value), $validKeys, true)
                ? null
                : (string) __('messages.menu.invalid_option', ['options' => $optionsList]),
        );

        return trim($selected);
    }

    /**
     * Pause execution after running a submenu command until the user presses Enter or any key.
     */
    protected function waitForKeyPress(): void
    {
        if (! isset($this->input) || ! $this->input->isInteractive()) {
            return;
        }

        $prompt = (string) __('messages.menu.press_any_key');

        if (! defined('STDIN') || ! defined('STDOUT')) {
            return;
        }

        fwrite(STDOUT, PHP_EOL . '  ' . $prompt . ' ');
        fflush(STDOUT);

        if (PHP_OS_FAMILY !== 'Windows' && $this->isInteractiveTty() && Process::isTtySupported()) {
            if ($this->setUnixSttyMode(['-icanon', '-echo'])) {
                try {
                    @fread(STDIN, 1);
                } finally {
                    $this->setUnixSttyMode(['sane']);
                }
                fwrite(STDOUT, PHP_EOL);

                return;
            }
        }

        @fgets(STDIN);
    }

    /**
     * Read a single valid numeric keystroke immediately on Unix/Linux interactive terminals using stty.
     *
     * @param  array<int, string>  $validKeys
     */
    protected function readSingleKeyUnix(array $validKeys, string $optionsList): ?string
    {
        if (! $this->setUnixSttyMode(['-icanon', '-echo'])) {
            return null;
        }

        fwrite(STDOUT, '  > ' . (string) __('messages.menu.numeric_hint', ['options' => $optionsList]) . ': ');
        fflush(STDOUT);

        try {
            while (true) {
                $char = @fread(STDIN, 1);
                if ($char === false || $char === '') {
                    return null;
                }

                // Handle Ctrl+C (\x03) gracefully
                if ($char === "\x03") {
                    $this->setUnixSttyMode(['sane']);
                    fwrite(STDOUT, PHP_EOL);
                    exit(130);
                }

                if (in_array($char, $validKeys, true)) {
                    fwrite(STDOUT, $char . PHP_EOL);
                    fflush(STDOUT);

                    return $char;
                }
            }
        } finally {
            $this->setUnixSttyMode(['sane']);
        }
    }

    /**
     * Configure Unix terminal line settings safely via Symfony Process with argument arrays.
     *
     * @param  array<int, string>  $sttyArgs
     */
    protected function setUnixSttyMode(array $sttyArgs): bool
    {
        try {
            $process = new Process(['stty', ...$sttyArgs]);
            $process->setTty(true);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Detect whether the current process is running with Administrator (Windows) or Root (Linux) privileges.
     */
    protected function isElevatedPrivilege(): bool
    {
        if ($this->cachedIsElevated !== null) {
            return $this->cachedIsElevated;
        }

        try {
            /** @var ProcessExecutorInterface $executor */
            $executor = app(ProcessExecutorInterface::class);

            if (PHP_OS_FAMILY === 'Windows') {
                $result = $executor->run(['net', 'session'], 5);
                $this->cachedIsElevated = $result->successful;

                return $this->cachedIsElevated;
            }

            $this->cachedIsElevated = $executor->getEffectiveUid() === 0;

            return $this->cachedIsElevated;
        } catch (Throwable) {
            $this->cachedIsElevated = false;

            return false;
        }
    }
}
