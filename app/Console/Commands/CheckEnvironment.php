<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BrowserService;
use Throwable;

class CheckEnvironment extends Command
{
    protected $signature = 'app:health-check';
    protected $description = 'Глубокая диагностика доступности ЕИС с выгрузкой артефактов';

    public function handle(BrowserService $browserService)
    {
        $this->header("1. СИСТЕМНЫЕ РЕСУРСЫ");
        $shmSize = rescue(fn() => shell_exec('df -h /dev/shm | awk "NR==2 {print $2}"'), 'unknown');
        $this->line("✔ Память Chromium (/dev/shm): <fg=cyan>$shmSize</>");

        $this->header("2. ПРОВЕРКА CHROMIUM");
        $version = rescue(fn() => shell_exec('chromium --version'), 'не найден');
        $this->line("✔ " . trim($version));

        $proxy = \Sys\Models\Proxy\Proxy::where('is_active', true)->first();
        if (!$proxy) {
            $this->error("✘ Активные прокси в БД не найдены. Тесты отменены.");
            return 1;
        }

        $this->info("Использую прокси: {$proxy->ip_address}:{$proxy->port}");

        // ТЕСТ 0: google
        $this->header("3. ТЕСТ: google");
        $this->runTest($browserService, 'https://google.ru', 'google', $proxy);


        // ТЕСТ 1: ГЛАВНАЯ
        $this->header("4. ТЕСТ: ГЛАВНАЯ СТРАНИЦА");
        $this->runTest($browserService, 'https://zakupki.gov.ru', 'eis_main_page', $proxy);

        // ТЕСТ 2: ПОИСК (КАТАЛОГ)
        $this->header("5. ТЕСТ: ПОИСК / КАТАЛОГ (Тяжелая таблица)");
        $searchUrl = "https://zakupki.gov.ru/epz/order/extendedsearch/results.html?fz44=on&recordsPerPage=_10";
        $this->runTest($browserService, $searchUrl, 'eis_search_results', $proxy);

        $this->newLine();
        $this->info("Диагностика завершена. Скриншоты в: storage/app/browser/page_debug/");

        return 0;
    }

    protected function runTest(BrowserService $service, string $url, string $debugKey, $proxy)
    {
        $this->comment("Инициализация теста для [$debugKey]...");

        try {
            $service->close();

            // Используем сервис так же, как в боевом коде
            $page = $service->withRandomProxy()->openPage($url, [
                'debug' => ['key' => "health_check/{$debugKey}"],
                'onTimeout' => function ($page, $e) {
                    return false;
                } // На тесте таймаут — это ошибка
            ]);

            $data = $page->evaluate("
            (function() {
                return {
                    url: window.location.href,
                    isChromeError: window.location.href.startsWith('chrome-error://'),
                    title: document.title,
                    bodyText: document.body.innerText.substring(0, 500)
                };
            })()
        ")->getReturnValue();

            if ($data['isChromeError']) {
                $this->error("✘ СЕТЕВАЯ ОШИБКА: Браузер не смог соединиться через прокси.");
                $this->line("  <fg=gray>Подробности: " . $data['bodyText'] . "</>");
                return;
            }

            // ... остальная логика проверки контента (hasLogo, isBlocked и т.д.)
            $this->info("✔ Соединение установлено: " . $data['title']);
        } catch (Throwable $e) {
            $this->error("✘ СБОЙ: " . $e->getMessage());
        } finally {
            $service->close();
        }
    }

    protected function header($text)
    {
        $this->newLine();
        $this->line("<fg=black;bg=yellow;options=bold> $text </>");
    }
}
