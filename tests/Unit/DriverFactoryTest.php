<?php

declare(strict_types=1);

use App\Drivers\LinuxDriver;
use App\Drivers\WindowsDriver;
use App\Factories\SystemDriverFactory;

it('resolves WindowsDriver when target OS family is Windows', function (): void {
    $driver = SystemDriverFactory::make('Windows');

    expect($driver)->toBeInstanceOf(WindowsDriver::class);
});

it('resolves LinuxDriver when target OS family is Linux', function (): void {
    $driver = SystemDriverFactory::make('Linux');

    expect($driver)->toBeInstanceOf(LinuxDriver::class);
});

it('throws RuntimeException when OS family is unsupported', function (): void {
    SystemDriverFactory::make('FreeBSD');
})->throws(RuntimeException::class, 'Unsupported operating system family: FreeBSD');
