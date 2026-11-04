<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

use App\Dto\Procurement\Crawler\Contracts\HasArchiveWindow;
use App\Dto\Procurement\Crawler\Contracts\HasPriceRange;
use App\Dto\Procurement\Crawler\Contracts\RequiresScope;
use App\Enums\Procurement\Crawler\ListingSortField;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Типизированный контракт фильтра профиля обхода ЕИС (`prc_crawlers.filter`, jsonb):
 * типизация живёт на границе. Контракт принимает только параметры с подтверждённым
 * фильтрующим действием площадки.
 *
 * Класс несёт СМЫСЛ поля, а не представление ЕИС: имена query-параметров (`af`, `pc`,
 * `priceFromGeneral`, …) и формат даты `d.m.Y` остаются в `EisCsvUrlBuilder` — единственном
 * носителе ЕИС-специфики.
 */
final class EisSearchFilter extends Data implements HasArchiveWindow, HasPriceRange, RequiresScope
{
    /**
     * Единственный источник истины по допустимым ключам `prc_crawlers.filter` — используется
     * `validateAndCreate()` для отказа на неизвестном ключе (канон `RowAttributes::ALLOWED_KEYS`).
     * Отказ обязателен, а не желателен: ЕИС на неизвестный параметр не отвечает ошибкой и не
     * сужает выдачу, то есть опечатка молча РАСШИРЯЕТ выборку.
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
        'sortField',
        'sortAscending',
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
         * `platform listing price bound` в сканере. Ноль запрещён валидацией:
         * `priceToGeneral=0` обнуляет выдачу.
         */
        public ?int $priceTo = null,
        /**
         * Дата размещения «с» (`publishDateFrom`) имеет ТРИ роли: у fresh это обычный
         * фильтр выдачи; у archive-профиля БЕЗ явного `--from`/`--to` — ОКНО ПРОГОНА, которое
         * `StartCrawlerRunAction` читает через `HasArchiveWindow`; у delta-scan и backlog-rescan
         * профильное окно публикации в запрос не уходит — их курсор дренирует день обновления.
         * Посуточный override archive вкладывается в окно профиля. Форматы каста — оба, которые
         * реально приходят: ISO из собственного `toArray()` (round-trip через jsonb) и `Y-m-d` из
         * сида и формы.
         */
        #[WithCast(DateTimeInterfaceCast::class, format: [DATE_ATOM, 'Y-m-d'])]
        public ?CarbonImmutable $publishedFrom = null,
        /** Дата размещения «по» (`publishDateTo`) — те же три роли, что у `publishedFrom`. */
        #[WithCast(DateTimeInterfaceCast::class, format: [DATE_ATOM, 'Y-m-d'])]
        public ?CarbonImmutable $publishedTo = null,
        /** Строка поиска (`searchString`); принимает и реестровый номер. */
        public ?string $searchString = null,
        /** Морфология поиска (`morphology`); имеет смысл только вместе с `searchString`. */
        public bool $morphology = false,
        /**
         * Предметный скоуп профиля — КОДЫ ОКПД2 (не внутренние id площадки): в запрос они
         * приведут к `okpd2Ids` + `okpd2IdsWithNested=on` после резолва по карте
         * `prc_okpd2_nodes`, сам резолв здесь не живёт — DTO остаётся конструируемым без
         * БД. Пустым быть не может: профиль без скоупа расширяет выборку на весь реестр.
         *
         * @var list<string>
         */
        public array $okpd2Codes = [],
        /**
         * Поле сортировки выдачи (`sortBy`); `null` → билдер эмитит прежний дефолт
         * (`UPDATE_DATE`), сохраняя поведение уже лежащих в БД профилей.
         */
        public ?ListingSortField $sortField = null,
        /**
         * Направление сортировки — ПОЛОЖИТЕЛЬНОЕ утверждение («по возрастанию»), а НЕ строка
         * площадки: перевод в инвертированный `sortDirection` (`true`/`false`) выполняет
         * билдер и только он. Дефолт `false` воспроизводит
         * сегодняшнее поведение (`sortDirection='false'`, убывание).
         */
        public bool $sortAscending = false,
    ) {}

    /**
     * Гейт фиксированной схемы фильтра: `spatie/laravel-data` лишние ключи `from()`/
     * `validateAndCreate()` тихо игнорирует, поэтому неизвестный ключ отклоняется явно ДО
     * делегирования родителю — с именем ключа и списком допустимых.
     */
    /**
     * @param  Arrayable<array-key, mixed>|array<string, mixed>  $payload
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
            // пустую выгрузку. Нет верхней границы — поле остаётся `null`.
            'priceTo' => ['nullable', 'integer', 'min:1'],
            'priceFrom' => ['nullable', 'integer', 'min:0'],
            // Пустой скоуп расширяет выборку на весь реестр.
            'okpd2Codes' => ['required', 'array', 'min:1'],
            // Только форма кода и только как защита от мусора: проверка «код есть в карте»
            // здесь ЗАПРЕЩЕНА — членство обеспечивают форма (предлагает коды карты) и билдер
            // (отказ при нерезолве), а `Data`-класс обязан строиться без БД.
            'okpd2Codes.*' => ['string', 'regex:/^([A-Z]|\d{2}(\.\d+)*)$/'],
            // ЕИС молча игнорирует неизвестное значение sortBy, отвечая 200,
            // поэтому опечатки отклоняются до боевого запроса.
            'sortField' => ['nullable', 'string', Rule::enum(ListingSortField::class)],
            'sortAscending' => ['boolean'],
            'publishedFrom' => ['nullable', 'date'],
            // Окно archive-профиля: конец раньше начала молча развернул бы прогон на
            // отрицательный период — гейт ловит это на сохранении формы, а не на боевом запросе.
            'publishedTo' => ['nullable', 'date', 'after_or_equal:publishedFrom'],
        ];
    }

    /**
     * Стартовый ценовой стек `ArchiveWindowScanner`: потолок задаётся профилем
     * и редактируется из Filament.
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

    public function archiveWindowFrom(): ?CarbonImmutable
    {
        return $this->publishedFrom;
    }

    public function archiveWindowTo(): ?CarbonImmutable
    {
        return $this->publishedTo;
    }
}
