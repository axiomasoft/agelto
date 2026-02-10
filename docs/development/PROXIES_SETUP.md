# Настройка прокси-слоя для парсинга (Laravel + Asocks)

Данная документация описывает процесс интеграции мобильных прокси Asocks в проект Agelto для обхода блокировок ЕИС (zakupki.gov.ru) и других защищенных ресурсов.

---

## 1. Конфигурация в панели Asocks

Для стабильной работы парсера необходимо использовать **Dedicated Port** (Выделенный порт). Обычные общие порты часто блокируются на уровне протокола `CONNECT`.

### Настройки порта:

- **Device Type:** Только `Mobile` (максимальный уровень доверия от WAF).
- **Location:** `Russia (RU)` (критично для доступа к государственным ресурсам РФ).
- **Dedicated Port:** `Включен` ($0.01/день).
- **Traffic Limit:** Установите значение > 0 (например, 10 Гб), иначе прокси не будет пропускать трафик.
- **IP Authorization (Whitelist):** Добавьте внешний IP вашего сервера (`89.38.99.96`).

> **Важно:** Мобильные прокси требуют повышенных таймаутов. Минимально рекомендуемое время ожидания — 40-60 секунд.

---

## 2. Формат данных в базе данных

В таблице `proxies` данные должны храниться в следующем формате для корректной сборки URL:

| Поле           | Значение (Пример) | Примечание                              |
| :------------- | :---------------- | :-------------------------------------- |
| **ip_address** | `109.236.84.23`   | IP сервера Asocks                       |
| **port**       | `25168`           | **НЕ 443**, а выделенный порт из панели |
| **username**   | `01kh1k...`       | Логин из панели                         |
| **password**   | `QW8Ay1...`       | Пароль из панели                        |

---

## 3. Архитектура решения в Laravel

### Http Macro (`AppServiceProvider.php`)

Для унификации запросов используется макрос `withRandomProxy()`. Он решает следующие проблемы:

1. **SSL Error 60:** Игнорирует несовпадение имени хоста в сертификате прокси.
2. **Tunneling:** Принудительно использует `HTTP/1.1` для установления моста (`CONNECT`).
3. **User-Agent:** Подменяет отпечаток браузера.

```php
Http::macro('withRandomProxy', function (string $driver = 'database') {
    $proxyData = \App\Services\Http\Proxy\ProxyFactory::make($driver)->getProxy();

    if (!$proxyData) return $this;

    return $this->withOptions([
        'proxy'           => $proxyData['url'], // Формат http://user:pass@ip:port
        'verify'          => false,             // Отключает проверку SSL целевого сайта
        'timeout'         => 60,                // Мобильные прокси могут быть медленными
        'connect_timeout' => 20,
        'curl' => [
            \CURLOPT_HTTPPROXYTUNNEL      => true,
            \CURLOPT_PROXYAUTH            => \CURLAUTH_BASIC,
            \CURLOPT_HTTP_VERSION         => \CURL_HTTP_VERSION_1_1,
            \CURLOPT_PROXY_SSL_VERIFYPEER => false,
            \CURLOPT_PROXY_SSL_VERIFYHOST => false,
        ],
    ])->withHeaders([
        'User-Agent' => $proxyData['ua'],
        'Accept-Language' => 'ru-RU,ru;q=0.9',
    ]);
});
```
