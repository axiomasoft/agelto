<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use GuzzleHttp\Client as GuzzleClient;
use Gemini\Client as GeminiClient;
use Gemini\Laravel\Facades\Gemini;
use Override;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        // Переопределяем Singleton клиента Gemini
        $this->app->singleton(GeminiClient::class, function () {
            $httpClient = new GuzzleClient([
                'proxy'   => env('GEMINI_PROXY'),
                'verify'  => false,
                'connect_timeout' => 20,
                'timeout' => 300, // Увеличиваем до 5 минут для тяжелых смет
                'debug'   => true, // Включаем VERBOSE режим
            ]);

            return Gemini::factory()
                ->withApiKey(config('gemini.api_key'))
                ->withHttpClient($httpClient)
                ->make();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn(): ?Password => app()->isProduction()
                ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
                : null
        );
    }
}
