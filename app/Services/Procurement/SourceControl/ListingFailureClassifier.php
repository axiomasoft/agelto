<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Классификация отказа блока листинга: прокси / площадка / парсер.
 *
 * Классификатор — чистая функция над тем, что уже известно после запроса (HTTP-статус,
 * число записей, результат маппинга) или над исключением транспорта. HTTP-ретраи с прокси
 * к этому моменту уже исчерпаны (`CsvSourceMethod::requestWithRetries`), поэтому отказ —
 * факт про блок, а не про одну попытку.
 */
final readonly class ListingFailureClassifier
{
    /**
     * @param  int  $records  записей в ответе источника
     * @param  int  $mapped  записей, ставших снимками
     * @param  int  $rowFailures  записей, не прошедших маппинг или инжест
     * @param  list<string>  $missingColumns  обязательные колонки, которых нет в заголовке (preflight)
     * @param  bool  $htmlBody  тело 2xx-ответа — HTML-документ, а не данные
     */
    public function classifyResponse(
        int $httpStatus,
        int $records,
        int $mapped,
        int $rowFailures,
        array $missingColumns = [],
        bool $htmlBody = false,
    ): ?ListingFailureKind {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return $this->classifyStatus(status: $httpStatus);
        }

        // Порядок важен: HTML-заглушка тоже «без колонок», но это отказ площадки, а не смена схемы.
        if ($htmlBody) {
            return ListingFailureKind::HtmlInsteadOfData;
        }

        if ($missingColumns !== []) {
            return ListingFailureKind::SchemaDrift;
        }

        if ($records > 0 && $mapped === 0) {
            return ListingFailureKind::UnrecognizedFormat;
        }

        return $rowFailures > 0 ? ListingFailureKind::RowErrors : null;
    }

    public function classifyStatus(int $status): ListingFailureKind
    {
        return match (true) {
            $status === 407 => ListingFailureKind::ProxyFailure,
            in_array($status, [401, 403, 429, 451], true) => ListingFailureKind::SourceBlocked,
            $status === 408, $status >= 500 => ListingFailureKind::SourceUnavailable,
            default => ListingFailureKind::UnexpectedStatus,
        };
    }

    /**
     * Эвристика «это HTML, а не CSV/JSON»: тело начинается с `<!doctype`/`<html`/`<head`/`<body`.
     * Нужно обсудить: WAF ЕИС может отдавать и пустое тело, и JSON-ошибку — их ловят
     * `UnrecognizedFormat`/пустой блок; живой выборки заглушек в репозитории нет.
     */
    public function looksLikeHtml(string $body): bool
    {
        $head = mb_strtolower(ltrim(mb_substr($body, 0, 512)));

        return str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html')
            || str_starts_with($head, '<head') || str_starts_with($head, '<body');
    }

    /**
     * Транспортный отказ без HTTP-ответа. `null` — исключение не про источник (ошибка кода,
     * БД, lease) и должно подниматься дальше как есть.
     */
    public function classifyException(Throwable $exception): ?ListingFailureKind
    {
        if ($exception instanceof NoProxyAvailableException) {
            return ListingFailureKind::ProxyPoolExhausted;
        }

        if (! $exception instanceof ConnectionException) {
            return null;
        }

        // cURL различает отказ прокси-туннеля (CONNECT/407, «proxy») и отказ самой цели.
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'proxy') || str_contains($message, 'connect tunnel')
            ? ListingFailureKind::ProxyFailure
            : ListingFailureKind::SourceUnavailable;
    }
}
