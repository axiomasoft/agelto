<?php

namespace App\Services;

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Browser;
use HeadlessChromium\Page;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;
use Sys\Repositories\ProxyRepository;
use Sys\Models\Proxy\Proxy;
use Throwable;

class BrowserService
{
    protected string $browserPath = '/usr/bin/chromium';
    protected ?Browser $browser = null;
    protected ?Page $page = null;
    protected ?string $extPath = null;
    protected ?Proxy $currentProxyModel = null;

    protected ProxyRepository $proxyRepo;

    public function __construct(ProxyRepository $proxyRepo)
    {
        $this->proxyRepo = $proxyRepo;
    }

    /**
     * Инициализирует прокси через репозиторий.
     */
    public function withRandomProxy(string $driver = 'database'): self
    {
        $this->currentProxyModel = $this->proxyRepo->getRandomActiveProxy($driver);

        if (!$this->currentProxyModel) {
            return $this->setup();
        }

        return $this->setup([
            'ip'   => $this->currentProxyModel->ip_address,
            'port' => $this->currentProxyModel->port,
            'user' => $this->currentProxyModel->username,
            'pass' => $this->currentProxyModel->password,
        ]);
    }

    /**
     * Базовая настройка и запуск процесса Chromium.
     */
    public function setup(array $proxy = []): self
    {
        if ($this->browser) return $this;

        $this->extPath = $this->prepareProxyExtension($proxy);
        $factory = new BrowserFactory($this->browserPath);

        $flags = [
            '--no-sandbox',
            '--disable-ipv6',
            '--disable-blink-features=AutomationControlled',
            '--ignore-certificate-errors',
            '--lang=ru-RU,ru',
            // '--disable-dev-shm-usage', // Убрано: используем shm_size из docker-compose
        ];

        if (!empty($proxy['ip'])) {
            $flags[] = "--proxy-server=http://{$proxy['ip']}:{$proxy['port']}";
            if ($this->extPath) {
                $flags[] = "--load-extension={$this->extPath}";
                $flags[] = "--disable-extensions-except={$this->extPath}";
            }
        }

        $this->browser = $factory->createBrowser([
            'headless' => 'new',
            'windowSize' => [1920, 1080],
            'customFlags' => $flags
        ]);

        return $this;
    }

    /**
     * Переход на URL с поддержкой дебага и событий.
     * * @param string $url
     * @param array $options [
     * 'onTimeout' => function(Page $page, $exception): bool,
     * 'onError'   => function(Page $page, $exception): void,
     * 'debug'     => ['key' => 'unique_folder_name']
     * ]
     */
    public function openPage(string $url, array $options = []): Page
    {
        $options = array_merge([
            'timeout' => 90000,
            'debug' => false,
        ], $options);

        if (!$this->browser) $this->setup();

        if (!$this->page) {
            $this->page = $this->browser->createPage();
            $this->page->setUserAgent($this->getRealUserAgent());
            $this->page->addScriptTag(['content' => "Object.defineProperty(navigator, 'webdriver', {get: () => undefined});"]);
        }

        try {
            $this->page->navigate($url)->waitForNavigation(Page::LOAD, $options['timeout']);

            // Если все ок — сохраняем success-артефакты, если задан debug key
            $this->saveDebugArtifacts($options, 'success');
        } catch (\HeadlessChromium\Exception\OperationTimedOut $e) {
            if (isset($options['onTimeout']) && $options['onTimeout']($this->page, $e)) {
                $this->saveDebugArtifacts($options, 'success');
                return $this->page;
            }
            $this->handleFailure($e, $options);
            throw $e;
        } catch (Throwable $e) {
            $this->handleFailure($e, $options);
            throw $e;
        }

        return $this->page;
    }

    /**
     * Обработка сбоев: артефакты, колбеки и отчеты в БД.
     */
    protected function handleFailure(Throwable $e, array $options): void
    {
        $this->saveDebugArtifacts($options, 'error');

        if (isset($options['onError'])) {
            $options['onError']($this->page, $e);
        }

        $this->reportFail($e);
    }

    /**
     * Сохранение снимка страницы и HTML для отладки.
     */
    protected function saveDebugArtifacts(array $options, string $type): void
    {
        $key = $options['debug']['key'] ?? null;
        if (!$key || !$this->page) return;

        $dir = storage_path("app/browser/page_debug/{$key}");

        try {
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0775, true);
            }

            // HTML
            $html = $this->page->evaluate("document.documentElement.outerHTML")->getReturnValue();
            File::put("{$dir}/{$type}.html", $html);

            // Скриншот
            $this->page->screenshot([
                'format'  => 'jpeg',
                'quality' => 80,
            ])->saveToFile("{$dir}/{$type}_screenshot.jpg");
        } catch (Throwable $e) {
            // Ошибки дебага не должны прерывать основной цикл
        }
    }

    /**
     * Закрытие браузера и очистка временных папок.
     */
    public function close(): void
    {
        if ($this->browser) {
            $this->browser->close();
            $this->browser = null;
            $this->page = null;
        }

        if ($this->extPath && File::isDirectory($this->extPath)) {
            File::deleteDirectory($this->extPath);
            $this->extPath = null;
        }
    }

    /**
     * Формирует User-Agent на основе версии Chromium в системе.
     */
    protected function getRealUserAgent(): string
    {
        $process = new Process([$this->browserPath, '--version']);
        $process->run();
        preg_match('/(\d+\.\d+\.\d+\.\d+)/', $process->getOutput(), $matches);
        $version = $matches[1] ?? '132.0.0.0';

        return "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$version} Safari/537.36";
    }

    /**
     * Создание расширения для базовой авторизации прокси.
     */
    protected function prepareProxyExtension(array $proxy): ?string
    {
        if (empty($proxy['ip'])) return null;

        $path = storage_path('app/browser/proxy_ext_' . uniqid());
        File::makeDirectory($path, 0775, true);

        file_put_contents("$path/manifest.json", json_encode([
            "version" => "1.0.0",
            "manifest_version" => 2,
            "name" => "ProxyAuth",
            "permissions" => ["proxy", "tabs", "webRequest", "webRequestBlocking", "<all_urls>"],
            "background" => ["scripts" => ["background.js"]]
        ]));

        $js = sprintf(
            "var config = { mode: 'fixed_servers', rules: { singleProxy: { scheme: 'http', host: '%s', port: %d } } };
            chrome.proxy.settings.set({value: config, scope: 'regular'}, function() {});
            chrome.webRequest.onAuthRequired.addListener(
                function(details) { return { authCredentials: { username: '%s', password: '%s' } }; },
                {urls: ['<all_urls>']}, ['blocking']
            );",
            $proxy['ip'],
            $proxy['port'],
            $proxy['user'],
            $proxy['pass']
        );

        file_put_contents("$path/background.js", $js);

        return $path;
    }

    public function reportSuccess(): void
    {
        if ($this->currentProxyModel) {
            $this->proxyRepo->markSuccess($this->currentProxyModel->id);
        }
    }

    public function reportFail(Throwable $e): void
    {
        if ($this->currentProxyModel) {
            $this->proxyRepo->markFailed($this->currentProxyModel->id, $e);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
