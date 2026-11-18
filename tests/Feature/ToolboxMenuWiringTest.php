<?php

declare(strict_types=1);

use App\Commands\ToolboxMenuCommand;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Command\Command;

it('verifies every subcommand referenced in ToolboxMenuCommand is registered in the Laravel Zero Kernel', function (): void {
    /** @var Kernel $kernel */
    $kernel = $this->app->make(Kernel::class);
    $registeredCommands = $kernel->all();

    $menuCommands = ToolboxMenuCommand::getAllMenuCommands();

    expect($menuCommands)->not->toBeEmpty();

    foreach ($menuCommands as $commandName) {
        $name = $commandName instanceof Command
            ? (string) $commandName->getName()
            : (string) $commandName;

        expect($registeredCommands)->toHaveKey($name);

        $definition = $registeredCommands[$name]->getDefinition();
        expect($definition->hasOption('lang'))->toBeTrue();
    }
});

it('ensures every App\\Commands subcommand is exposed in ToolboxMenuCommand without orphans', function (): void {
    /** @var Kernel $kernel */
    $kernel = $this->app->make(Kernel::class);
    $registeredCommands = $kernel->all();
    $menuCommands = array_map(
        fn ($cmd): string => $cmd instanceof Command ? (string) $cmd->getName() : (string) $cmd,
        ToolboxMenuCommand::getAllMenuCommands()
    );

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

        foreach ($submenus as $items) {
            expect($items)->not->toBeEmpty();

            foreach ($items as $entry) {
                $translated = (string) __($entry['label_key']);
                expect($translated)->not->toBe($entry['label_key'])
                    ->and($translated)->not->toBeEmpty();
            }
        }

        $returnPrompt = (string) __('messages.menu.press_any_key');
        expect($returnPrompt)->toContain('[Enter]')
            ->and($returnPrompt)->toContain('[0]');
    }
});
