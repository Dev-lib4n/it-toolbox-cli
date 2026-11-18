<?php

declare(strict_types=1);

use LaravelZero\Framework\Application;

if (method_exists(Application::class, 'configure')) {
    return Application::configure(basePath: dirname(__DIR__))->create();
}

$app = new Application(
    dirname(__DIR__)
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    LaravelZero\Framework\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    Illuminate\Foundation\Exceptions\Handler::class
);

return $app;
