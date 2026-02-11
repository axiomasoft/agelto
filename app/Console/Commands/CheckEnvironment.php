<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Services\BrowserService;
use Throwable;

class CheckEnvironment extends Command
{
    protected $signature = 'app:health-check';
    protected $description = 'Глубокая диагностика доступности ЕИС';

    public function handle(BrowserService $browserService)
    {
        $this->header("1. Системные ресурсы");
        $shmSize = rescue(fn() => shell_exec('df -h /dev/shm | awk "NR==2 {print $2}"'), 'unknown');
        $this->line("✔ Память Chromium (/dev/shm): $shmSize");

        $this->header("2. Проверка Chromium");
        $version = rescue(fn() => shell_exec('chromium --version'), 'не найден');
        $this->line("✔ " . trim($version));

        $proxy = \Sys\Models\Proxy\Proxy::where('is_active', true)->first();
        if (!$proxy) {
            $this->error("✘ Активные прокси в БД не найдены. Тесты ЕИС отменены.");
            return 1;
        }

        $this->info("Использую прокси: {$proxy->ip_address}:{$proxy->port}");

        $this->header("3. Тест: ГЛАВНАЯ ЕИС (Легкий запрос)");
        $this->runTest($browserService, 'https://zakupki.gov.ru', 'ЕИС Главная', $proxy);

        $this->header("4. Тест: РЕЗУЛЬТАТЫ ПОИСКА (Тяжелый запрос)");
        // Минимальный URL поиска для проверки рендеринга таблицы
        $searchUrl = "https://zakupki.gov.ru/epz/order/extendedsearch/results.html?fz44=on&recordsPerPage=_10";
        $this->runTest($browserService, $searchUrl, 'ЕИС Поиск', $proxy);

        return 0;
    }

    protected function runTest(BrowserService $service, string $url, string $label, $proxy)
    {
        $this->comment("Загрузка $url...");

        try {
            $service->close();
            $start = microtime(true);

            $proxyData = [
                'ip'   => $proxy->ip_address,
                'port' => $proxy->port,
                'user' => $proxy->username,
                'pass' => $proxy->password,
            ];

            $page = $service->setup($proxyData)->openPage($url);

            // Небольшая пауза для рендеринга динамических элементов
            usleep(1500000);

            // Глубокая проверка DOM
            $check = $page->evaluate("
                (function() {
                    const text = document.body.innerText;
                    return {
                        title: document.title,
                        isEis: text.includes('Единая информационная система') || text.includes('zakupki.gov.ru'),
                        hasLogo: document.querySelector('.logo-title') !== null || document.querySelector('.header__logo') !== null,
                        hasResults: document.querySelector('.search-registry-entry-block') !== null,
                        isBlocked: text.includes('Access Denied') || text.includes('заблокировано') || text.includes('403 Forbidden')
                    };
                })()
            ")->getReturnValue();

            $time = round(microtime(true) - $start, 2);

            if ($check['isEis'] || $check['hasLogo'] || $check['hasResults']) {
                $status = $check['hasResults'] ? "Данные найдены" : "Доступ есть";
                $this->info("✔ $label: УСПЕХ ($time сек) — $status");
            } elseif ($check['isBlocked']) {
                $this->error("✘ $label: БЛОКИРОВКА (IP сервера или прокси в бане ЕИС)");
            } else {
                $this->error("✘ $label: Неизвестный ответ. Title: '{$check['title']}'");
            }
        } catch (Throwable $e) {
            $this->error("✘ $label: ОШИБКА -> " . $e->getMessage());
        } finally {
            $service->close();
        }
    }

    protected function header($text)
    {
        $this->newLine();
        $this->line("<fg=yellow;options=bold>$text</>");
        $this->line(str_repeat('-', 40));
    }
}
