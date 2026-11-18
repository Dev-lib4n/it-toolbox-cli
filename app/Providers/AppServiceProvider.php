<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SystemDriverInterface;
use App\Factories\SystemDriverFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerTranslator();
        $this->registerSystemDriver();
    }

    /**
     * Register the translation loader and translator service for en and pt_BR.
     */
    protected function registerTranslator(): void
    {
        $this->app->instance('path.lang', base_path('lang'));

        if (! $this->app->bound('files')) {
            $this->app->singleton('files', fn (): Filesystem => new Filesystem());
        }

        $this->app->singleton('translation.loader', function ($app): FileLoader {
            return new FileLoader($app['files'], $app['path.lang']);
        });

        $this->app->singleton('translator', function ($app): Translator {
            $loader = $app['translation.loader'];
            $locale = $app['config']->get('app.locale', 'en');
            $fallback = $app['config']->get('app.fallback_locale', 'en');

            $translator = new Translator($loader, $locale);
            $translator->setFallback($fallback);

            return $translator;
        });

        $this->app->alias('translator', Translator::class);
        $this->app->alias('translator', \Illuminate\Contracts\Translation\Translator::class);
    }

    /**
     * Bind the cross-platform SystemDriverInterface into the container.
     */
    protected function registerSystemDriver(): void
    {
        $this->app->singleton(SystemDriverInterface::class, function (): SystemDriverInterface {
            return SystemDriverFactory::make();
        });
    }
}
