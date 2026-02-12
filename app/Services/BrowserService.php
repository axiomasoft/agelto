<?php

namespace App\Services;

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Browser;
use HeadlessChromium\Page;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Sys\Repositories\ProxyRepository;
use Sys\Models\Proxy\Proxy;
use Throwable;

class BrowserService
{
    protected string $browserPath = '/usr/bin/chromium';
    protected ?Browser $browser = null;
    protected ?Page $page = null;
    protected ?string $extPath = null;
    protected ?string $userDataDir = null;
    protected ?Proxy $currentProxyModel = null;

    protected ProxyRepository $proxyRepo;

    public function __construct(ProxyRepository $proxyRepo)
    {
        $this->proxyRepo = $proxyRepo;
    }

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

        // Фиксированная папка для root
        $this->userDataDir = storage_path("app/browser/profile_root");

        if (!File::isDirectory($this->userDataDir)) {
            File::makeDirectory($this->userDataDir, 0777, true, true);
        }

        $this->extPath = $this->prepareProxyExtension($proxy);
        $factory = new BrowserFactory($this->browserPath);

        $flags = [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--disable-software-rasterizer',
            '--user-data-dir=' . $this->userDataDir,
            '--lang=ru-RU,ru',
            '--disable-crash-reporter',
            '--no-crash-upload',
            '--disable-breakpad',
            '--noerrdialogs',
            '--disable-dbus', // Добавим этот флаг, чтобы приглушить ошибки из твоего лога
        ];

        if (!empty($proxy['ip'])) {
            $flags[] = "--proxy-server=http://{$proxy['ip']}:{$proxy['port']}";
            $flags[] = '--host-resolver-rules=MAP * ~NOTFOUND , EXCLUDE ' . $proxy['ip'];
            if ($this->extPath) {
                $flags[] = "--load-extension={$this->extPath}";
                $flags[] = "--disable-extensions-except={$this->extPath}";
            }
        }

        $this->browser = $factory->createBrowser([
            'headless' => 'new',
            'windowSize' => [1920, 1080],
            'customFlags' => $flags,
            'startupTimeout' => 60,
        ]);

        return $this;
    }

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
            $this->saveDebugArtifacts($options, 'success');
        } catch (Throwable $e) {
            $this->handleFailure($e, $options);
            throw $e;
        }

        return $this->page;
    }

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
        // Не используем isConnected() для совместимости с IDE, полагаемся на try-catch
        if (!$this->page || !$this->browser) {
            return;
        }

        if (empty($options['debug']) || empty($options['debug']['key'])) {
            return;
        }

        $key = $options['debug']['key'];
        $dir = storage_path("app/browser/page_debug/{$key}");

        try {
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0775, true);
            }

            // Пытаемся получить HTML. Если Chrome завис или упал, вылетит Exception
            $evaluation = $this->page->evaluate("document.documentElement.outerHTML");
            $html = $evaluation->getReturnValue();

            if ($html) {
                File::put("{$dir}/{$type}.html", $html);
            }

            $this->page->screenshot([
                'format'  => 'jpeg',
                'quality' => 70,
            ])->saveToFile("{$dir}/{$type}_screenshot.jpg");
        } catch (Throwable $e) {
            Log::warning("BrowserService Debug Artifacts Fail: " . $e->getMessage());
        }
    }

    public function close(): void
    {
        if ($this->browser) {
            try {
                $this->browser->close();
            } catch (Throwable $e) {
            }
            $this->browser = null;
            $this->page = null;
        }

        // Удаляем временное расширение прокси
        if ($this->extPath && File::isDirectory($this->extPath)) {
            File::deleteDirectory($this->extPath);
            $this->extPath = null;
        }

        // Папку профиля userDataDir НЕ удаляем здесь, так как Chromium
        // иногда завершает процессы асинхронно. Чистим ее через Schedule.
    }

    protected function getRealUserAgent(): string
    {
        try {
            $process = new Process([$this->browserPath, '--version']);
            $process->run();
            preg_match('/(\d+\.\d+\.\d+\.\d+)/', $process->getOutput(), $matches);
            $version = $matches[1] ?? '132.0.0.0';
        } catch (Throwable $e) {
            $version = '132.0.0.0';
        }

        return "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$version} Safari/537.36";
    }

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
