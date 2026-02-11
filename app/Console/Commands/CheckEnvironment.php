<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

class CheckEnvironment extends Command
{
    protected $signature = 'app:health-check';
    protected $description = 'Проверка готовности сервера к работе краулера';

    public function handle()
    {
        $this->header("1. Проверка файловой системы");
        $paths = [
            storage_path('app'),
            storage_path('app/browser'),
            storage_path('logs'),
            base_path('public'),
        ];

        foreach ($paths as $path) {
            if (!File::isDirectory($path)) {
                File::makeDirectory($path, 0777, true);
                $this->warn("! Создана папка: $path");
            }

            if (is_writable($path)) {
                $this->info("✔ $path доступна для записи");
            } else {
                $this->error("✘ $path НЕДОСТУПНА для записи");
            }
        }

        $this->header("2. Проверка Chromium");
        $process = new Process(['/usr/bin/chromium', '--version']);
        $process->run();
        if ($process->isSuccessful()) {
            $this->info("✔ Chromium: " . trim($process->getOutput()));
        } else {
            $this->error("✘ Chromium не найден по пути /usr/bin/chromium");
        }

        $this->header("3. Проверка сети и прокси");
        // Проверка прямого соединения
        $this->checkUrl('https://google.com', 'Прямой доступ в интернет');

        // Проверка прокси из БД
        $proxy = \Sys\Models\Proxy\Proxy::where('is_active', true)->first();
        if ($proxy) {
            $this->info("Использую прокси для теста: {$proxy->ip_address}");
            $this->checkUrl('https://zakupki.gov.ru', 'Доступ к ЕИС через прокси', $proxy);
        } else {
            $this->warn("! Активные прокси в БД не найдены");
        }

        return 0;
    }

    protected function checkUrl($url, $label, $proxy = null)
    {
        $this->info("Проверка $label...");

        try {
            // Используем твой сервис напрямую
            $browserService = app(\App\Services\BrowserService::class);

            $start = microtime(true);

            // Настраиваем прокси, если он передан
            if ($proxy) {
                $browserService->setup([
                    'ip'   => $proxy->ip_address,
                    'port' => $proxy->port,
                    'user' => $proxy->username,
                    'pass' => $proxy->password,
                ]);
            } else {
                $browserService->setup();
            }

            $page = $browserService->openPage($url);
            $title = $page->evaluate('document.title')->getReturnValue();

            $time = round(microtime(true) - $start, 2);

            if ($title) {
                $this->info("✔ $label: УСПЕХ! Title: '$title' ($time сек)");
            } else {
                $this->error("✘ $label: Страница загрузилась, но Title пустой");
            }
        } catch (\Exception $e) {
            $this->error("✘ $label: ОШИБКА БРАУЗЕРА: " . $e->getMessage());

            // Если упало, проверим — создалась ли папка расширения?
            $this->checkExtensionDir();
        } finally {
            if (isset($browserService)) {
                $browserService->close();
            }
        }
    }

    protected function checkExtensionDir()
    {
        $files = glob(storage_path('app/browser/proxy_ext_*'));
        if (empty($files)) {
            $this->warn("! Внимание: Папка расширения не была создана в storage/app/browser/");
        } else {
            $this->info("i Найдены временные папки расширений: " . count($files));
        }
    }

    protected function header($text)
    {
        $this->newLine();
        $this->line("<fg=yellow;options=bold>$text</>");
        $this->line(str_repeat('-', 40));
    }
}
