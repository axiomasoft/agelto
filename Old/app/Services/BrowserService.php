<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\Browser\BrowserOptions;
use App\Interfaces\Crawling\BrowserClient;
use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Exception\NavigationExpired;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Page;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Sys\Models\Proxy\Proxy;
use Sys\Repositories\ProxyRepository;
use Throwable;

final class BrowserService implements BrowserClient
{
    private string $browserPath = '/usr/bin/chromium';

    private ?Browser $browser = null;

    private ?Page $page = null;

    private ?string $userDataDir = null;

    private ?Proxy $currentProxyModel = null;

    private ProxyRepository $proxyRepo;

    public function __construct(ProxyRepository $proxyRepo)
    {
        $this->proxyRepo = $proxyRepo;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Базовая настройка и запуск процесса Chromium.
     */
    public function setup(array $proxy = []): self
    {
        if ($this->browser) {
            return $this;
        }

        $this->userDataDir = storage_path('app/browser/profile_root');

        if (! File::isDirectory($this->userDataDir)) {
            File::makeDirectory($this->userDataDir, 0777, true, true);
        }

        $factory = new BrowserFactory($this->browserPath);

        $flags = [
            // Системные настройки для Docker/VPS
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--disable-software-rasterizer',
            '--user-data-dir='.$this->userDataDir,
            '--lang=ru-RU,ru',

            // Отключаем лишние фоновые процессы
            '--disable-blink-features=AutomationControlled',
            '--disable-background-networking',
            '--disable-background-timer-throttling',
            '--disable-backgrounding-occluded-windows',
            '--disable-renderer-backgrounding',
            '--disable-service-worker',
            '--disable-features=IsolateOrigins,site-per-process',
            '--disable-canvas-aa',
            '--disable-2d-canvas-clip-aa',
            '--disable-notifications',
            '--single-process',

            // Анонимность и сеть
            '--disable-webrtc',
            '--disable-features=WebRtcHideLocalIpsWithMdns',
            '--ignore-certificate-errors',
            '--ignore-ssl-errors',
            '--disable-dbus',

            // Экономия ресурсов (отключаем загрузку картинок на уровне флагов)
            '--blink-settings=imagesEnabled=false',
            '--disable-remote-fonts',
        ];

        if (! empty($proxy['ip'])) {
            $flags[] = "--proxy-server=http://{$proxy['ip']}:{$proxy['port']}";
            $flags[] = '--host-resolver-rules=MAP * ~NOTFOUND , EXCLUDE '.$proxy['ip'];
        }

        $this->browser = $factory->createBrowser([
            'headless' => true,
            'windowSize' => [1920, 1080],
            'customFlags' => $flags,
            'startupTimeout' => 60,
        ]);

        return $this;
    }

    public function withRandomProxy(string $driver = 'database'): self
    {
        $this->currentProxyModel = $this->proxyRepo->getRandomActiveProxy($driver);

        if (! $this->currentProxyModel) {
            return $this->setup();
        }

        return $this->setup([
            'ip' => $this->currentProxyModel->ip_address,
            'port' => $this->currentProxyModel->port,
            'user' => $this->currentProxyModel->username,
            'pass' => $this->currentProxyModel->password,
        ]);
    }

    public function openPage(string $url, ?BrowserOptions $options = null): Page
    {
        $opts = $options ?? new BrowserOptions();

        $attempt = 0;

        while ($attempt < $opts->retries) {
            $attempt++;

            try {
                if (! $this->browser) {
                    $this->setup();
                }

                if (! $this->page) {
                    $this->page = $this->browser->createPage();

                    // --- БЛОКИРОВКА РЕСУРСОВ ЧЕРЕЗ CDP (ДЛЯ СКОРОСТИ) ---
                    $session = $this->page->getSession();
                    $session->sendMessageSync(new Message('Network.enable'));
                    $session->sendMessageSync(new Message('Network.setBlockedURLs', [
                        'urls' => [
                            '*.png',
                            '*.jpg',
                            '*.jpeg',
                            '*.gif',
                            '*.svg',
                            '*.webp',
                            '*.woff',
                            '*.woff2',
                            '*.ttf',
                            '*.otf',
                            '*mc.yandex.ru*',
                            '*google-analytics.com*',
                            '*doubleclick.net*',
                            '*stat.gov.ru*',
                            '*googletagmanager.com*',
                        ],
                    ]));

                    if (
                        $this->currentProxyModel &&
                        ! empty($this->currentProxyModel->username) &&
                        ! empty($this->currentProxyModel->password)
                    ) {
                        /** @disregard P1013 */
                        $this->page->authenticate(
                            $this->currentProxyModel->username,
                            $this->currentProxyModel->password
                        );
                    }

                    $this->page->setUserAgent($this->getRealUserAgent());

                    $this->page->addScriptTag(['content' => "
                        Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
                        window.chrome = { runtime: {} };
                        Object.defineProperty(navigator, 'languages', {get: () => ['ru-RU', 'ru']});
                        Object.defineProperty(navigator, 'platform', {get: () => 'Win32'});
                    "]);
                }

                // Используем DOM_CONTENT_LOADED для ускорения парсинга
                $this->page->navigate($url)->waitForNavigation(Page::DOM_CONTENT_LOADED, $opts->timeout);

                $this->saveDebugArtifacts($opts, 'success');

                return $this->page;
            } catch (Throwable $e) {
                $isTimeout = $e instanceof OperationTimedOut || $e instanceof NavigationExpired;

                // Если попытки исчерпаны или это не таймаут (при условии что мы ретраим только таймауты)
                if ($attempt >= $opts->retries) {
                    $this->handleFailure($e, $opts);
                    throw $e;
                }

                $this->logger()->warning("BrowserService: Attempt {$attempt} failed. Reason: {$e->getMessage()}. Retry in {$opts->retry_timeout}s...");

                if ($opts->rotate_proxy_on_fail) {
                    $this->rotateProxy();
                }

                sleep($opts->retry_timeout);
            }
        }

        throw new RuntimeException('BrowserService: Unexpected end of openPage loop.');
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

    public function rotateProxy(): self
    {
        $this->close();

        // Вызываем уже существующий метод, который выберет новый IP из БД и сделает setup()
        return $this->withRandomProxy();
    }

    private function handleFailure(Throwable $e, BrowserOptions $options): void
    {
        $this->saveDebugArtifacts($options, 'error');

        if (is_callable($options->onError)) {
            ($options->onError)($this->page, $e);
        }

        $this->reportFail($e);
    }

    private function saveDebugArtifacts(BrowserOptions $options, string $type): void
    {
        if (! $this->page || ! $this->browser) {
            return;
        }

        $key = $options->getDebugKey();
        if ($key === null || $key === '') {
            return;
        }

        $dir = storage_path("app/browser/page_debug/{$key}");

        try {
            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0775, true);
            }

            $evaluation = $this->page->evaluate('document.documentElement.outerHTML');
            $html = $evaluation->getReturnValue();

            if ($html) {
                File::put("{$dir}/{$type}.html", $html);
            }

            $this->page->screenshot([
                'format' => 'jpeg',
                'quality' => 70,
            ])->saveToFile("{$dir}/{$type}_screenshot.jpg");
        } catch (Throwable $e) {
            Log::warning('BrowserService Debug Artifacts Fail: '.$e->getMessage());
        }
    }

    private function getRealUserAgent(): string
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    }

    private function logger()
    {
        return Log::channel('stack');
    }
}
