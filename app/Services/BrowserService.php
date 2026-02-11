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
     * Возвращает $this для цепочки вызовов: $browser->withRandomProxy()->openPage($url)
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
        if ($this->browser) {
            return $this;
        }

        $this->extPath = $this->prepareProxyExtension($proxy);
        $factory = new BrowserFactory($this->browserPath);

        $this->browser = $factory->createBrowser([
            'headless' => 'new',
            'windowSize' => [1920, 1080],
            'customFlags' => array_filter([
                '--no-sandbox',
                '--disable-ipv6',
                '--disable-dev-shm-usage',
                '--disable-blink-features=AutomationControlled',
                '--blink-settings=imagesEnabled=false',
                '--ignore-certificate-errors',
                "--proxy-server=http://{$proxy['ip']}:{$proxy['port']}",
                '--lang=ru-RU,ru',
                $this->extPath ? "--load-extension={$this->extPath}" : null,
                $this->extPath ? "--disable-extensions-except={$this->extPath}" : null,
            ])
        ]);

        return $this;
    }

    /**
     * Переход на URL. Создает страницу, если она еще не открыта.
     */
    public function openPage(string $url): Page
    {
        if (!$this->browser) {
            $this->setup();
        }

        if (!$this->page) {
            $this->page = $this->browser->createPage();
            $this->page->setUserAgent($this->getRealUserAgent());

            // Скрытие признаков автоматизации (Stealth)
            $this->page->addScriptTag([
                'content' => "Object.defineProperty(navigator, 'webdriver', {get: () => undefined});"
            ]);
        }

        $this->page->navigate($url)->waitForNavigation(Page::DOM_CONTENT_LOADED, 60000);

        return $this->page;
    }

    /**
     * Быстрый метод для одиночных задач.
     */
    public function run(string $url, callable $callback, array $proxy = [])
    {
        try {
            $page = $this->setup($proxy)->openPage($url);
            $result = $callback($page, $this->browser);
            $this->reportSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->reportFail($e);
            throw $e;
        } finally {
            $this->close();
        }
    }

    /**
     * Сообщить об успешном выполнении в репозиторий (сброс ошибок).
     */
    public function reportSuccess(): void
    {
        if ($this->currentProxyModel) {
            $this->proxyRepo->markSuccess($this->currentProxyModel->id);
        }
    }

    /**
     * Сообщить о сбое в репозиторий (инкремент ошибок + текст ошибки).
     */
    public function reportFail(Throwable $e): void
    {
        if ($this->currentProxyModel) {
            $this->proxyRepo->markFailed($this->currentProxyModel->id, $e);
        }
    }

    /**
     * Закрытие браузера и очистка временных файлов расширений.
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
     * Формирует актуальный User-Agent на основе версии Chromium в системе.
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
     * Подготовка расширения Chrome для авторизации прокси.
     */
    protected function prepareProxyExtension(array $proxy): ?string
    {
        if (empty($proxy['ip'])) return null;

        // Гарантируем уникальную ПАПКУ внутри storage
        $path = storage_path('app/browser/proxy_ext_' . uniqid());

        // Создаем директорию
        if (!File::makeDirectory($path, 0777, true, true)) {
            throw new \Exception("Не удалось создать директорию для расширения по пути: $path");
        }

        $manifest = [
            "version" => "1.0.0",
            "manifest_version" => 2,
            "name" => "ProxyAuth",
            "permissions" => ["proxy", "tabs", "webRequest", "webRequestBlocking", "<all_urls>"],
            "background" => ["scripts" => ["background.js"]]
        ];

        file_put_contents("$path/manifest.json", json_encode($manifest));

        $js = sprintf(
            "
        var config = {
            mode: 'fixed_servers',
            rules: { singleProxy: { scheme: 'http', host: '%s', port: %d } }
        };
        chrome.proxy.settings.set({value: config, scope: 'regular'}, function() {});
        chrome.webRequest.onAuthRequired.addListener(
            function(details) {
                return { authCredentials: { username: '%s', password: '%s' } };
            },
            {urls: ['<all_urls>']},
            ['blocking']
        );",
            $proxy['ip'],
            $proxy['port'],
            $proxy['user'],
            $proxy['pass']
        );

        file_put_contents("$path/background.js", $js);

        return $path;
    }

    public function __destruct()
    {
        $this->close();
    }
}
