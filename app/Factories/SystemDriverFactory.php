<?php

declare(strict_types=1);

namespace App\Factories;

use App\Contracts\SystemDriverInterface;
use App\Drivers\LinuxDriver;
use App\Drivers\WindowsDriver;
use RuntimeException;

class SystemDriverFactory
{
    /**
     * Create the appropriate system driver instance for the target OS family.
     *
     * @param  string|null  $osFamily  Defaults to PHP_OS_FAMILY when null.
     * @return SystemDriverInterface
     *
     * @throws RuntimeException
     */
    public static function make(?string $osFamily = null): SystemDriverInterface
    {
        $family = $osFamily ?? PHP_OS_FAMILY;

        return match ($family) {
            'Windows' => function_exists('app') ? app(WindowsDriver::class) : new WindowsDriver(),
            'Linux' => function_exists('app') ? app(LinuxDriver::class) : new LinuxDriver(),
            default => throw new RuntimeException(
                sprintf('Unsupported operating system family: %s', $family)
            ),
        };
    }
}
