<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Http\Proxy\ProxyFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Override;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Http::macro('withRandomProxy', function (string $driver = 'database') {
            // Получаем данные прокси (url должен быть формата http://user:pass@ip:port)
            $proxyData = \App\Services\Http\Proxy\ProxyFactory::make($driver)->getProxy();

            if (!$proxyData) {
                return $this;
            }

            return $this->withOptions([
                'proxy' => $proxyData['url'],
                'verify' => false,
                'timeout'         => 60, // Общее время ожидания
                'connect_timeout' => 20, // Время на установку связи с прокси
                'curl' => [
                    \CURLOPT_HTTPPROXYTUNNEL => true,

                    // Если PHP всё равно ругается на PROXY_HTTP_VERSION,
                    // используй просто CURLOPT_HTTP_VERSION (она работает для всего канала)
                    \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,

                    \CURLOPT_PROXY_SSL_VERIFYPEER => false,
                    \CURLOPT_PROXY_SSL_VERIFYHOST => false,
                    \CURLOPT_SSLVERSION => \CURL_SSLVERSION_TLSv1_2,
                ],
            ])->withHeaders([
                'User-Agent'      => $proxyData['ua'],
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control'   => 'no-cache',
                'Connection'      => 'keep-alive',
                'Sec-Ch-Ua'       => '"Not A(Brand";v="99", "Google Chrome";v="121", "Chromium";v="121"',
                'Sec-Ch-Ua-Mobile' => '?0',
                'Sec-Ch-Ua-Platform' => '"Windows"',
                'Upgrade-Insecure-Requests' => '1',
            ]);
        });
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
