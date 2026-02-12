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

        $this->userDataDir = storage_path("app/browser/profile_root");

        if (!File::isDirectory($this->userDataDir)) {
            File::makeDirectory($this->userDataDir, 0777, true, true);
        }

        $factory = new BrowserFactory($this->browserPath);

        $flags = [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--disable-software-rasterizer',
            '--user-data-dir=' . $this->userDataDir,
            '--lang=ru-RU,ru',
            '--disable-blink-features=AutomationControlled', // Скрывает статус бота
            '--disable-webrtc',                              // Блокирует утечку IP сервера
            '--disable-features=WebRtcHideLocalIpsWithMdns', // Доп. защита IP
            '--disable-dbus',
            '--disable-crash-reporter',
            '--no-crash-upload',
            '--disable-breakpad',
            '--noerrdialogs',
        ];

        if (!empty($proxy['ip'])) {
            // Устанавливаем прокси сервер
            $flags[] = "--proxy-server=http://{$proxy['ip']}:{$proxy['port']}";
            // Запрещаем DNS-запросы мимо прокси (Анонимность DNS)
            $flags[] = '--host-resolver-rules=MAP * ~NOTFOUND , EXCLUDE ' . $proxy['ip'];
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

            // Нативная авторизация (вместо расширения)
            if ($this->currentProxyModel) {
                $this->page->authenticate(
                    $this->currentProxyModel->username,
                    $this->currentProxyModel->password
                );
            }

            $this->page->setUserAgent($this->getRealUserAgent());

            // Глубокий Stealth: маскируем navigator.webdriver и добавляем объекты Chrome
            $this->page->addScriptTag(['content' => "
                Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
                window.chrome = { runtime: {} };
                Object.defineProperty(navigator, 'languages', {get: () => ['ru-RU', 'ru']});
            "]);
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

    protected function saveDebugArtifacts(array $options, string $type): void
    {
        if (!$this->page || !$this->browser) return;

        if (empty($options['debug']) || empty($options['debug']['key'])) return;

        $key = $options['debug']['key'];
        $dir = storage_path("app/browser/page_debug/{$key}");

        try {
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0775, true);
            }

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
    }

    protected function getRealUserAgent(): string
    {
        // Имитируем реальный браузер на Windows
        return "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36";
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
