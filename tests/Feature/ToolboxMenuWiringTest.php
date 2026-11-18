<?php

declare(strict_types=1);

use App\Commands\ToolboxMenuCommand;
use Illuminate\Contracts\Console\Kernel;

it('verifies every subcommand referenced in ToolboxMenuCommand is registered in the Laravel Zero Kernel', function (): void {
    /** @var Kernel $kernel */
    $kernel = $this->app->make(Kernel::class);
    $registeredCommands = $kernel->all();

    $menuCommands = ToolboxMenuCommand::getAllMenuCommands();

    expect($menuCommands)->not->toBeEmpty();

    foreach ($menuCommands as $commandName) {
        expect($registeredCommands)->toHaveKey(
            $commandName,
            sprintf('Menu references unregistered command "%s".', $commandName)
        );

        $definition = $registeredCommands[$commandName]->getDefinition();
        expect($definition->hasOption('lang'))->toBeTrue(
            sprintf('Command "%s" must accept --lang option when invoked from ToolboxMenuCommand.', $commandName)
        );
    }
});

it('ensures every App\\Commands subcommand is exposed in ToolboxMenuCommand without orphans', function (): void {
    /** @var Kernel $kernel */
    $kernel = $this->app->make(Kernel::class);
    $registeredCommands = $kernel->all();
    $menuCommands = ToolboxMenuCommand::getAllMenuCommands();

    $appCommands = [];
    foreach ($registeredCommands as $name => $commandInstance) {
        $class = get_class($commandInstance);
        if (str_starts_with($class, 'App\\Commands\\') && $class !== ToolboxMenuCommand::class) {
            $appCommands[] = $name;
        }
    }

    sort($appCommands);
    $sortedMenu = $menuCommands;
    sort($sortedMenu);

    expect($sortedMenu)->toEqual($appCommands);
});

it('verifies all submenu label translation keys exist in both en and pt_BR', function (): void {
    $submenus = ToolboxMenuCommand::getSubmenuDefinitions();

    foreach (['en', 'pt_BR'] as $locale) {
        app()->setLocale($locale);
        app('translator')->setLocale($locale);

        foreach ($submenus as $category => $items) {
            expect($items)->not->toBeEmpty("Submenu '{$category}' must not be empty");

            foreach ($items as $key => $entry) {
                $translated = (string) __($entry['label_key']);
                expect($translated)->not->toBe($entry['label_key'], "Missing translation '{$entry['label_key']}' for locale '{$locale}'")
                    ->and($translated)->not->toBeEmpty();
            }
        }
    }
});
