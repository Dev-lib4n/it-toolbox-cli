<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

it('maintains identical translation keys between en and pt_BR dictionaries', function (): void {
    $en = require base_path('lang/en/messages.php');
    $ptBr = require base_path('lang/pt_BR/messages.php');

    $enKeys = array_keys(Arr::dot($en));
    $ptBrKeys = array_keys(Arr::dot($ptBr));

    sort($enKeys);
    sort($ptBrKeys);

    expect($ptBrKeys)->toEqual($enKeys);
});

it('translates strings in both en and pt_BR locales', function (): void {
    app()->setLocale('en');
    app('translator')->setLocale('en');
    expect(__('messages.menu.main_prompt'))->toBe('Select a module or action:');

    app()->setLocale('pt_BR');
    app('translator')->setLocale('pt_BR');
    expect(__('messages.menu.main_prompt'))->toBe('Selecione um módulo ou ação:');
});
