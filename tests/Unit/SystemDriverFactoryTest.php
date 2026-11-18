<?php

declare(strict_types=1);

use App\Drivers\LinuxDriver;
use App\Drivers\WindowsDriver;
use App\DTOs\ActionResult;
use App\Factories\SystemDriverFactory;

it('resolves WindowsDriver when OS family is Windows', function (): void {
    $driver = SystemDriverFactory::make('Windows');

    expect($driver)->toBeInstanceOf(WindowsDriver::class);
});

it('resolves LinuxDriver when OS family is Linux', function (): void {
    $driver = SystemDriverFactory::make('Linux');

    expect($driver)->toBeInstanceOf(LinuxDriver::class);
});

it('throws RuntimeException for unsupported OS families', function (string $unsupportedOs): void {
    SystemDriverFactory::make($unsupportedOs);
})->with(['Solaris', 'FreeBSD', 'Darwin'])->throws(RuntimeException::class);

it('creates an immutable ActionResult DTO', function (): void {
    $result = new ActionResult(
        success: true,
        message: 'messages.maintenance.clean_success',
        details: ['deleted_files' => 10],
    );

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('messages.maintenance.clean_success')
        ->and($result->details)->toBe(['deleted_files' => 10]);
});
