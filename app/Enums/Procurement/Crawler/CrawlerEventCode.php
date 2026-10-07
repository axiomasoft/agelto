<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

use Filament\Support\Contracts\HasLabel;

/**
 * Единый каталог событий краулера: стабильный внутренний код (не HTTP-статус) + важность.
 *
 * Конвенция: событие публикует `SourceEventReporter` — строка журнала `prc_source_incidents`
 * (колонка `type` = код, `SourceIncidentPublisher`) и запись в стандартный лог Laravel
 * (`SourceEventLogger`) с уровнем `severity()` и сообщением `"<КОД>: <текст>"`; теги в контексте:
 * `code`, `platform_id`, `stage`, `crawler_id`, `run_id`, `fetch_id`, `failure_kind`,
 * `http_status`, `cursor`, `request_params` (только непустые). Канал лога —
 * штатный, настроенный в `LOG_CHANNEL` (в проде `json`).
 *
 * Коды не переименовываются (по ним ищут в логах и строятся алерты); новый случай — новый код.
 */
enum CrawlerEventCode: string implements HasLabel
{
    /** CSV пришёл с пустым телом — «невозможный» ответ. Блок пропущен, обход идёт дальше. */
    case CsvEmpty = 'CRAWLER_CSV_EMPTY';

    /** CSV не скачался: 408/5xx/ошибка соединения. Блок пропущен. */
    case CsvSourceUnavailable = 'CRAWLER_CSV_SOURCE_UNAVAILABLE';

    /** Площадка отказала: 401/403/429/451. Блок пропущен. */
    case CsvSourceBlocked = 'CRAWLER_CSV_SOURCE_BLOCKED';

    /** Нет прокси или прокси ответил 407. Блок пропущен. */
    case CsvProxyUnavailable = 'CRAWLER_CSV_PROXY_UNAVAILABLE';

    /** Блок не найден (404). По умолчанию только лог. */
    case CsvNotFound = 'CRAWLER_CSV_NOT_FOUND';

    /** Прочий не-2xx. Блок пропущен. */
    case CsvUnexpectedStatus = 'CRAWLER_CSV_UNEXPECTED_STATUS';

    /** В заголовке CSV нет обязательных колонок — изменился формат выгрузки. Блок пропущен. */
    case CsvLayoutDrift = 'CRAWLER_CSV_LAYOUT_DRIFT';

    /** Записи есть, но ни одна не разобрана. Блок пропущен. */
    case CsvInvalidContent = 'CRAWLER_CSV_INVALID_CONTENT';

    /** Ошибка нашего кода при обработке блока. */
    case CsvCodeError = 'CRAWLER_CSV_CODE_ERROR';

    /** Блок принят, часть строк не разобрана. */
    case CsvRowErrors = 'CRAWLER_CSV_ROW_ERRORS';

    /** На карточке нет базовых селекторов — изменилась вёрстка. Карточка не обработана. */
    case CardLayoutDrift = 'CRAWLER_CARD_LAYOUT_DRIFT';

    /** Карточку не получить: сеть, прокси, 5xx или чужая страница (после штатных ретраев). */
    case CardUnavailable = 'CRAWLER_CARD_UNAVAILABLE';

    /** Карточка не найдена (404). По умолчанию только лог. */
    case CardNotFound = 'CRAWLER_CARD_NOT_FOUND';

    /** Ошибка нашего кода при обработке карточки. Карточка пропущена, брейкер не трогается. */
    case CardCodeError = 'CRAWLER_CARD_CODE_ERROR';

    /** Этап ушёл в ожидание по таймеру после серии сетевых/площадочных отказов. */
    case StageWaiting = 'CRAWLER_STAGE_WAITING';

    /** Этап остановлен до решения оператора: серия отказов «изменилась структура». */
    case StageHeld = 'CRAWLER_STAGE_HELD';

    /** Прогон приостановлен, потому что этап листинга не принимает запросы. */
    case RunSuspended = 'CRAWLER_RUN_SUSPENDED';

    /** Приостановленный прогон возобновлён. */
    case RunResumed = 'CRAWLER_RUN_RESUMED';

    case ManualPause = 'CRAWLER_MANUAL_PAUSE';
    case ManualResume = 'CRAWLER_MANUAL_RESUME';

    /** Код события для отказа — единственное место соответствия «этап × вид отказа → код». */
    public static function forFailure(SourceStage $stage, SourceFailureKind $kind): self
    {
        if ($stage === SourceStage::Detail) {
            return match ($kind) {
                SourceFailureKind::LayoutDrift => self::CardLayoutDrift,
                SourceFailureKind::CodeError => self::CardCodeError,
                SourceFailureKind::NotFound => self::CardNotFound,
                default => self::CardUnavailable,
            };
        }

        return match ($kind) {
            SourceFailureKind::EmptyBody => self::CsvEmpty,
            SourceFailureKind::SourceUnavailable => self::CsvSourceUnavailable,
            SourceFailureKind::SourceBlocked => self::CsvSourceBlocked,
            SourceFailureKind::ProxyUnavailable => self::CsvProxyUnavailable,
            SourceFailureKind::NotFound => self::CsvNotFound,
            SourceFailureKind::UnexpectedStatus => self::CsvUnexpectedStatus,
            SourceFailureKind::LayoutDrift => self::CsvLayoutDrift,
            SourceFailureKind::InvalidContent => self::CsvInvalidContent,
            SourceFailureKind::RowErrors => self::CsvRowErrors,
            SourceFailureKind::CodeError => self::CsvCodeError,
        };
    }

    public function severity(): SourceIncidentSeverity
    {
        return match ($this) {
            self::CsvEmpty, self::StageHeld, self::StageWaiting, self::ManualPause => SourceIncidentSeverity::Critical,
            self::CardCodeError, self::CsvCodeError => SourceIncidentSeverity::Error,
            self::RunResumed, self::ManualResume, self::CsvRowErrors => SourceIncidentSeverity::Info,
            default => SourceIncidentSeverity::Warning,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::CsvEmpty => 'CSV: пустой ответ',
            self::CsvSourceUnavailable => 'CSV: не скачался',
            self::CsvSourceBlocked => 'CSV: площадка блокирует',
            self::CsvProxyUnavailable => 'CSV: прокси недоступен',
            self::CsvUnexpectedStatus => 'CSV: неожиданный статус',
            self::CsvLayoutDrift => 'CSV: изменился формат',
            self::CsvInvalidContent => 'CSV: записи не разобраны',
            self::CsvRowErrors => 'CSV: ошибки строк',
            self::CardLayoutDrift => 'Карточка: изменилась вёрстка',
            self::CardUnavailable => 'Карточка: не получена',
            self::CardNotFound => 'Карточка: не найдена',
            self::CsvNotFound => 'CSV: не найден',
            self::CardCodeError => 'Карточка: ошибка кода',
            self::CsvCodeError => 'CSV: ошибка кода',
            self::StageWaiting => 'Этап в ожидании',
            self::StageHeld => 'Этап остановлен до оператора',
            self::RunSuspended => 'Прогон приостановлен',
            self::RunResumed => 'Прогон возобновлён',
            self::ManualPause => 'Пауза оператором',
            self::ManualResume => 'Возобновление оператором',
        };
    }
}
