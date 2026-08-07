<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Dto\Procurement\Crawler\Contracts\HasPriceRange;
use App\Dto\Procurement\Crawler\Contracts\RequiresScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Типизированный контракт фильтра профиля обхода ЕИС (`prc_crawlers.filter`, jsonb —
 * схема не меняется, D34: типизация живёт на границе). Состав полей —
 * `research/05-change-tracking.md` раздел 4: ТОЛЬКО параметры со статусом «подтверждено»
 * в `artifacts/eis-filter-params.json`; параметр, чьё фильтрующее действие не наблюдалось,
 * в контракт не входит — иначе предположение закрепляется кодом.
 *
 * Класс несёт СМЫСЛ поля, а не представление ЕИС: имена query-параметров (`af`, `pc`,
 * `priceFromGeneral`, …) и формат даты `d.m.Y` остаются в `EisCsvUrlBuilder` — единственном
 * носителе ЕИС-специфики (Code Guidance P4.3).
 */
final class EisSearchFilter extends Data implements HasPriceRange, RequiresScope
{
    /**
     * Единственный источник истины по допустимым ключам `prc_crawlers.filter` — используется
     * `validateAndCreate()` для отказа на неизвестном ключе (канон `RowAttributes::ALLOWED_KEYS`).
     * Отказ обязателен, а не желателен: ЕИС на неизвестный параметр не отвечает ошибкой и не
     * сужает выдачу (`findings/P4-eis-recon.md` §6), то есть опечатка молча РАСШИРЯЕТ выборку.
     *
     * @var list<string>
     */
    public const array ALLOWED_KEYS = [
        'stageApplications',
        'stageCommission',
        'stageCompleted',
        'stageCancelled',
        'priceFrom',
        'priceTo',
        'publishedFrom',
        'publishedTo',
        'searchString',
        'morphology',
        'okpd2Codes',
    ];

    public function __construct(
        /** Этап «Подача заявок» (`af`). */
        public bool $stageApplications = false,
        /** Этап «Работа комиссии» (`ca`). */
        public bool $stageCommission = false,
        /** Этап «Закупка завершена» (`pc`). */
        public bool $stageCompleted = false,
        /** Этап «Закупка отменена» (`pa`). */
        public bool $stageCancelled = false,
        /** Нижняя граница НМЦК, включительно (`priceFromGeneral`). */
        public ?int $priceFrom = null,
        /**
         * Верхняя граница НМЦК, включительно (`priceToGeneral`); `null` → дефолт
         * `config('crawler.archive.price_ceiling')` в сканере. Ноль запрещён валидацией:
         * `priceToGeneral=0` обнуляет выдачу (`findings/P4-eis-recon.md` §5.2, Р-4).
         */
        public ?int $priceTo = null,
        /**
         * Дата размещения «с» (`publishDateFrom`) — только для профилей БЕЗ окна: в
         * archive/delta-прогоне окно ставит сканер (`EisCsvUrlBuilder::archiveOverrides()`
         * перекрывает оба ключа), фильтр его не дублирует. Форматы каста — оба, которые реально
         * приходят: ISO из собственного `toArray()` (round-trip через jsonb) и `Y-m-d` из сида и
         * формы.
         */
        #[WithCast(DateTimeInterfaceCast::class, format: [DATE_ATOM, 'Y-m-d'])]
        public ?CarbonImmutable $publishedFrom = null,
        /** Дата размещения «по» (`publishDateTo`) — та же оговорка, что у `publishedFrom`. */
        #[WithCast(DateTimeInterfaceCast::class, format: [DATE_ATOM, 'Y-m-d'])]
        public ?CarbonImmutable $publishedTo = null,
        /** Строка поиска (`searchString`); принимает и реестровый номер. */
        public ?string $searchString = null,
        /** Морфология поиска (`morphology`); имеет смысл только вместе с `searchString`. */
        public bool $morphology = false,
        /**
         * Предметный скоуп профиля — КОДЫ ОКПД2 (не внутренние id площадки, D9): в запрос они
         * приведут к `okpd2Ids` + `okpd2IdsWithNested=on` после резолва по карте
         * `prc_okpd2_nodes` (P2.3), сам резолв здесь не живёт — DTO остаётся конструируемым без
         * БД. Пустым быть не может (D18): профиль без скоупа расширяет выборку на весь реестр.
         *
         * @var list<string>
         */
        public array $okpd2Codes = [],
    ) {}

    /**
     * Гейт фиксированной схемы фильтра: `spatie/laravel-data` лишние ключи `from()`/
     * `validateAndCreate()` тихо игнорирует, поэтому неизвестный ключ отклоняется явно ДО
     * делегирования родителю — с именем ключа и списком допустимых (Implementation Rules P4.3 п. 3).
     */
    public static function validateAndCreate(Arrayable|array $payload): static
    {
        $raw = $payload instanceof Arrayable ? $payload->toArray() : $payload;
        $unknown = array_diff(array_keys($raw), self::ALLOWED_KEYS);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filter' => sprintf(
                    'Неизвестный ключ фильтра ЕИС: %s. Допустимые ключи: %s.',
                    implode(', ', $unknown),
                    implode(', ', self::ALLOWED_KEYS),
                ),
            ]);
        }

        return parent::validateAndCreate($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            // Ноль здесь не «пустое значение», а боевой отказ: `priceToGeneral=0` возвращает
            // пустую выгрузку (Р-4). Нет верхней границы — поле остаётся `null`.
            'priceTo' => ['nullable', 'integer', 'min:1'],
            'priceFrom' => ['nullable', 'integer', 'min:0'],
            // Обязательность — решение владельца (D18), а не удобство: см. докблок поля.
            'okpd2Codes' => ['required', 'array', 'min:1'],
            // Только форма кода и только как защита от мусора: проверка «код есть в карте»
            // здесь ЗАПРЕЩЕНА — членство обеспечивают форма (предлагает коды карты) и билдер
            // (отказ при нерезолве, D21), а `Data`-класс обязан строиться без БД.
            'okpd2Codes.*' => ['string', 'regex:/^([A-Z]|\d{2}(\.\d+)*)$/'],
        ];
    }

    /**
     * Стартовый ценовой стек `ArchiveWindowScanner` (P4.6, П-9): владелец 2026-08-05 п. 1 —
     * «потолок обязан быть полем профиля и правиться из Filament, а не жить константой конфига».
     * Незаполненная верхняя граница отдаёт `null` — сканер падает на `config`-дефолт.
     */
    public function startingPriceInterval(): ?string
    {
        if ($this->priceTo === null) {
            return null;
        }

        return sprintf('%d:%d', $this->priceFrom ?? 0, $this->priceTo);
    }

    public function hasScope(): bool
    {
        return $this->okpd2Codes !== [];
    }
}
