<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Services\BrowserService;
use Throwable;

class CheckEnvironment extends Command
{
    protected $signature = 'app:health-check';
    protected $description = 'Проверка готовности сервера к работе краулера';

    public function handle(BrowserService $browserService)
    {
        $this->header("1. Проверка файловой системы");
        $paths = [
            storage_path('app/browser'),
            storage_path('logs'),
            base_path('public'),
        ];

        foreach ($paths as $path) {
            if (!File::isDirectory($path)) {
                File::makeDirectory($path, 0775, true);
                $this->warn("! Создана папка: $path");
            }
            $this->line(is_writable($path) ? "✔ $path доступна" : "✘ $path ОШИБКА ЗАПИСИ");
        }

        $this->header("2. Проверка Chromium");
        // Пытаемся получить версию бинарника напрямую
        $version = rescue(fn() => shell_exec('chromium --version'), 'не найден');
        $this->line("✔ Бинарник: " . trim($version));

        $this->header("3. Проверка сети и прокси (через браузер)");

        // Тест 1: Без прокси
        $this->runTest($browserService, 'https://google.com', 'Прямой доступ');

        // Тест 2: С прокси
        $proxy = \Sys\Models\Proxy\Proxy::where('is_active', true)->first();
        if ($proxy) {
            $this->info("Использую прокси: {$proxy->ip_address}");
            $this->runTest($browserService, 'https://zakupki.gov.ru', 'Доступ к ЕИС', $proxy);
        } else {
            $this->warn("! Активные прокси в БД не найдены");
        }

        return 0;
    }

    protected function runTest(BrowserService $service, string $url, string $label, $proxy = null)
    {
        $this->info("Проверка: $label...");

        try {
            // Обязательно закрываем старый инстанс перед новым тестом
            $service->close();

            $start = microtime(true);

            $proxyData = $proxy ? [
                'ip'   => $proxy->ip_address,
                'port' => $proxy->port,
                'user' => $proxy->username,
                'pass' => $proxy->password,
            ] : [];

            $page = $service->setup($proxyData)->openPage($url);
            $title = $page->evaluate('document.title')->getReturnValue();
            $time = round(microtime(true) - $start, 2);

            if ($title) {
                $this->info("✔ $label: УСПЕХ! Title: '$title' ($time сек)");
            } else {
                $this->error("✘ $label: Загружена пустая страница");
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
