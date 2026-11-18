<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

it('ensures lang/en/messages.php and lang/pt_BR/messages.php have identical keys with zero missing entries', function (): void {
    $en = require base_path('lang/en/messages.php');
    $ptBr = require base_path('lang/pt_BR/messages.php');

    $enFlat = Arr::dot($en);
    $ptBrFlat = Arr::dot($ptBr);

    $missingInPtBr = array_diff_key($enFlat, $ptBrFlat);
    $missingInEn = array_diff_key($ptBrFlat, $enFlat);

    expect($missingInPtBr)->toBeEmpty()
        ->and($missingInEn)->toBeEmpty()
        ->and(count($enFlat))->toBe(count($ptBrFlat));

    foreach ($enFlat as $key => $value) {
        expect($value)->toBeString()->not->toBeEmpty("Key {$key} in 'en' must not be empty");
    }

    foreach ($ptBrFlat as $key => $value) {
        expect($value)->toBeString()->not->toBeEmpty("Key {$key} in 'pt_BR' must not be empty");
    }
});

it('switches translator output dynamically between en and pt_BR', function (): void {
    app()->setLocale('en');
    app('translator')->setLocale('en');

    expect(__('messages.menu.main_prompt'))->toBe('Select a module or action:')
        ->and(__('messages.common.permission_denied'))->toContain('Administrator');

    app()->setLocale('pt_BR');
    app('translator')->setLocale('pt_BR');

    expect(__('messages.menu.main_prompt'))->toBe('Selecione um módulo ou ação:')
        ->and(__('messages.common.permission_denied'))->toContain('Administrador');
});
