<?php

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Schemas;

use App\Dto\Procurement\Crawler\CrawlerRuntimeOptions;
use App\Dto\Procurement\Crawler\EisSearchFilter;
use App\Enums\Procurement\Crawler\CrawlerExecutionLane;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Filament\Resources\Procurement\Crawler\Proxies\Schemas\ProxyForm;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Platform\Okpd2NodeReadRepository;
use App\Services\Procurement\CrawlerFilterFactory;
use App\Services\Procurement\Eis\EisCsvUrlBuilder;
use Cron\CronExpression;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class CrawlerForm
{
    /**
     * Флаги колонок CSV-выгрузки ЕИС → русское название колонки (`artifacts/eis-filter-params.json`
     * `column_flags.items`, разведка P4.1, 25 флагов). Единственный носитель этого перечня в
     * рантайме — второй список «какие бывают колонки» не заводится.
     *
     * @var array<string, string>
     */
    private const array COLUMN_FLAG_LABELS = [
        'placementCsv' => 'Закон',
        'registryNumberCsv' => 'Реестровый номер закупки',
        'stepOrderPlacementCsv' => 'Этап закупки',
        'methodOrderPurchaseCsv' => 'Способ определения поставщика (подрядчика, исполнителя), подрядной организации',
        'nameOrderCsv' => 'Наименование закупки',
        'purchaseNumbersCsv' => 'Идентификационный код закупки (ИКЗ)',
        'numberLotCsv' => 'Номер лота',
        'nameLotCsv' => 'Наименование лота',
        'maxContractPriceCsv' => 'Начальная (максимальная) цена контракта (договора)',
        'currencyCodeCsv' => 'Валюта',
        'maxPriceContractCurrencyCsv' => 'Начальная (максимальная) цена в валюте контракта',
        'currencyCodeContractCurrencyCsv' => 'Валюта контракта',
        'scopeOkdpCsv' => 'Классификация по ОКДП',
        'scopeOkpdCsv' => 'Классификация по ОКПД',
        'scopeOkpd2Csv' => 'Классификация по ОКПД2',
        'scopeKtruCsv' => 'Код позиции',
        'ea615ItemCsv' => 'Предмет электронного аукциона',
        'customerNameCsv' => 'Наименование Заказчика',
        'organizationOrderPlacementCsv' => 'Организация, осуществляющая размещение',
        'publishDateCsv' => 'Дата размещения',
        'lastDateChangeCsv' => 'Дата обновления',
        'startDateRequestCsv' => 'Начало подачи заявок',
        'endDateRequestCsv' => 'Окончание подачи заявок',
        'ea615DateCsv' => 'Проведение электронного аукциона',
        'featureOrderPlacementCsv' => 'Особенности размещения заказа / Преимущества (44-ФЗ)',
    ];

    /**
     * Флаги, без которых боевой прогон не работает и которые поэтому нельзя снять в форме
     * (`research/05-change-tracking.md` §5.1): `registryNumberCsv` — источник `dedup_signature`
     * cap-детекта, `publishDateCsv`/`lastDateChangeCsv` — `EisCsvRowMapper` отбрасывает строку
     * без даты размещения или обновления.
     *
     * @var list<string>
     */
    private const array MANDATORY_COLUMN_FLAGS = [
        'registryNumberCsv',
        'publishDateCsv',
        'lastDateChangeCsv',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('registry_id')
                    ->relationship('registry', 'name')
                    ->live()
                    ->required(),
                Textarea::make('name')
                    ->required()
                    ->columnSpanFull(),
                Select::make('mode')
                    ->options(CrawlerMode::class)
                    ->required(),
                Select::make('execution_lane')
                    ->label('Контур исполнения')
                    ->options([
                        CrawlerExecutionLane::Standard->value => 'Стандартный — до 5 минут',
                        CrawlerExecutionLane::Long->value => 'Длинный архивный — до 1 часа',
                    ])
                    ->default(CrawlerExecutionLane::Standard->value)
                    ->required()
                    ->helperText('Ограничивает время одной job, а не весь crawl; к сметам и детальным карточкам не относится.'),
                Select::make('stop_policy')
                    ->label('Режим работы')
                    ->options(RunStopPolicy::class)
                    ->required()
                    ->helperText(
                        'Останавливает fresh-прогон на времени старта предыдущего завершённого прогона '
                        .'(с запасом) — работает, ТОЛЬКО пока выдача отсортирована по дате обновления '
                        .'убывающе (поле «Сортировка выдачи» ниже не заполнено или — «Дата обновления» '
                        .'по убыванию). Доступно только профилю с режимом «fresh».',
                    ),
                Select::make('source_method')
                    ->options(SourceMethod::class)
                    ->required(),
                Select::make('fallback_method')
                    ->options(SourceMethod::class),
                Fieldset::make('Фильтр площадки')
                    ->columnSpanFull()
                    ->schema(
                        fn (Get $get): array => self::filterFields(
                            filterClass: self::resolveFilterClass(registryId: $get('registry_id')),
                            registryId: $get('registry_id'),
                        ),
                    ),
                CheckboxList::make('options.columns')
                    ->label('Колонки CSV-выгрузки')
                    ->options(self::COLUMN_FLAG_LABELS)
                    ->columns(columns: 2)
                    ->columnSpanFull()
                    ->disableOptionWhen(
                        callback: static fn (string $value): bool => in_array($value, self::MANDATORY_COLUMN_FLAGS, strict: true),
                    )
                    ->afterStateHydrated(static function (CheckboxList $component, mixed $state): void {
                        $enabled = is_array($state)
                            ? array_keys(array_filter($state, static fn (mixed $value): bool => $value === true))
                            : [];

                        $component->state(array_values(array_unique([...$enabled, ...self::MANDATORY_COLUMN_FLAGS])));
                    })
                    ->dehydrateStateUsing(static function (array $state): array {
                        $selected = array_unique([...$state, ...self::MANDATORY_COLUMN_FLAGS]);

                        return array_fill_keys(array_values($selected), true);
                    })
                    ->helperText(
                        'Обязательные колонки (реестровый номер, дата размещения, дата обновления) нельзя '
                        .'снять — без них EisCsvRowMapper отбрасывает строку боевой выгрузки. Наблюдение '
                        .'2026-08-06: классификаторные колонки (ОКДП/ОКПД/ОКПД2/«Код позиции») на всех '
                        .'снятых замерах приходят пустыми — их выбор данных не добавит, отбор по ОКПД2 '
                        .'делает только поле выше.',
                    ),
                Fieldset::make('Переопределения листинга')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('options.retries')
                            ->label('Попытки')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Пусто — как у площадки.'),
                        TextInput::make('options.retryDelayMs')
                            ->label('Пауза перед повтором, мс')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Пусто — как у площадки.'),
                        TextInput::make('options.minPauseMs')
                            ->label('Пауза между запросами от, мс')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Пусто — как у площадки; 0 — явное значение.'),
                        TextInput::make('options.maxPauseMs')
                            ->label('Пауза между запросами до, мс')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Пусто — как у площадки; 0 — явное значение.'),
                    ]),
                TextInput::make('schedule')
                    ->label('Расписание (cron)')
                    ->helperText(
                        'Активный fresh-профиль (`mode=fresh`, `is_active=true`) регистрируется в '
                        .'планировщике на КАЖДОМ artisan-вызове по этому cron-выражению (`routes/console.php`).',
                    )
                    ->rules([
                        static function (): \Closure {
                            return static function (string $attribute, mixed $value, \Closure $fail): void {
                                if (blank($value)) {
                                    return;
                                }

                                if (! CronExpression::isValidExpression((string) $value)) {
                                    $fail('Невалидное cron-выражение.');
                                }
                            };
                        },
                    ])
                    ->columnSpanFull(),
                Placeholder::make('url_preview')
                    ->label('Пример URL выгрузки (from=1, to=500)')
                    ->columnSpanFull()
                    ->content(fn (Get $get): string => self::previewUrl(get: $get)),
                Toggle::make('is_active')
                    ->required(),
                Toggle::make('use_proxy')
                    ->required(),
                Select::make('proxy_country')
                    ->options(ProxyForm::COUNTRIES),
            ]);
    }

    private static function resolveFilterClass(mixed $registryId): ?string
    {
        if (! is_numeric($registryId)) {
            return null;
        }

        $registry = Registry::find($registryId);

        if (! $registry instanceof Registry) {
            return null;
        }

        try {
            return app(CrawlerFilterFactory::class)->for(registry: $registry);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Узлы карты ОКПД2 площадки выбранного реестра — единственное чтение репозитория P2.1,
     * переиспользуемое опциями формы и подписью пустой карты (P2.4).
     *
     * @return Collection<int, Okpd2Node>
     */
    private static function okpd2Nodes(mixed $registryId): Collection
    {
        if (! is_numeric($registryId)) {
            return collect();
        }

        $registry = Registry::find($registryId);

        if (! $registry instanceof Registry) {
            return collect();
        }

        return app(Okpd2NodeReadRepository::class)->selectableNodes(platformId: $registry->platform_id);
    }

    private static function okpd2OptionLabel(Okpd2Node $node): string
    {
        return sprintf('%s — %s', (string) $node->getAttribute('code'), (string) $node->getAttribute('name'));
    }

    /**
     * Опции мультиселекта ОКПД2, сгруппированные по `parent_code` (узлы без родителя — группа
     * «Разделы»); чистая функция из уже загруженной коллекции узлов, без запроса в цикле (P2.4).
     *
     * @return array<string, array<string, string>>
     */
    private static function okpd2CodeOptions(Collection $nodes): array
    {
        $labelsByCode = $nodes->mapWithKeys(
            static fn (Okpd2Node $node): array => [(string) $node->getAttribute('code') => self::okpd2OptionLabel($node)],
        );

        return $nodes
            ->groupBy(static fn (Okpd2Node $node): string => (string) ($node->getAttribute('parent_code') ?? ''))
            ->mapWithKeys(static function (Collection $group, string $parentCode) use ($labelsByCode): array {
                $groupLabel = $parentCode === '' ? 'Разделы' : ($labelsByCode->get($parentCode) ?? $parentCode);

                return [
                    $groupLabel => $group
                        ->mapWithKeys(static fn (Okpd2Node $node): array => [
                            (string) $node->getAttribute('code') => self::okpd2OptionLabel($node),
                        ])
                        ->all(),
                ];
            })
            ->all();
    }

    /**
     * Поле выбора ОКПД2 — сгруппированный поисковый мультиселект (P2.4); карта площадки пуста
     * (сид не прогнан) → вместо пустого обязательного селекта отрисовывается `Placeholder` с
     * причиной и путём починки, а не молчаливо нерабочее поле.
     */
    private static function okpd2CodeField(mixed $registryId): Select|Placeholder
    {
        $nodes = self::okpd2Nodes(registryId: $registryId);

        if ($nodes->isEmpty()) {
            return Placeholder::make('filter_okpd2_empty')
                ->label('ОКПД2')
                ->content(
                    'Карта ОКПД2 этой площадки пуста — прогоните сид (`Okpd2NodeSeeder`) либо команду '
                    .'пересборки карты (`crawler:okpd2-sync`, P2.5). Без карты профиль сохранить нельзя.',
                );
        }

        return Select::make('filter.okpd2Codes')
            ->label('ОКПД2')
            ->multiple()
            ->required()
            ->searchable()
            ->options(self::okpd2CodeOptions(nodes: $nodes))
            // Чисто числовой код («43») PHP превращает в int-ключ массива опций —
            // `EisSearchFilter::rules()` требует `string` (Implementation Rules п. 2:
            // DTO не смягчается ради формы), поэтому состояние приводится обратно к строкам.
            ->dehydrateStateUsing(static fn (mixed $state): array => array_map(
                strval(...),
                is_array($state) ? $state : [],
            ))
            ->helperText(
                'Параметр ЕИС: okpd2Ids. Выбранный узел означает «этот узел и всё, что под ним» '
                .'(okpd2IdsWithNested=on) — без этого флага выдача ЕИС пуста. Профиль обязан нести '
                .'хотя бы один узел.',
            );
    }

    /**
     * Поля секции «Фильтр площадки» — по резолвленному классу фильтра, не по коду реестра
     * (`if ($code === 'fz44')` запрещён Implementation Rules п. 1): другая площадка со своим
     * `Data`-классом в `config('crawler.filters')` получает свою ветку здесь же.
     *
     * @return array<int, mixed>
     */
    private static function filterFields(?string $filterClass, mixed $registryId): array
    {
        return match ($filterClass) {
            EisSearchFilter::class => [
                self::okpd2CodeField(registryId: $registryId),
                Toggle::make('filter.stageApplications')
                    ->label('Этап «Подача заявок»')
                    ->helperText('Параметр ЕИС: af'),
                Toggle::make('filter.stageCommission')
                    ->label('Этап «Работа комиссии»')
                    ->helperText('Параметр ЕИС: ca'),
                Toggle::make('filter.stageCompleted')
                    ->label('Этап «Закупка завершена»')
                    ->helperText('Параметр ЕИС: pc'),
                Toggle::make('filter.stageCancelled')
                    ->label('Этап «Закупка отменена»')
                    ->helperText('Параметр ЕИС: pa'),
                TextInput::make('filter.priceFrom')
                    ->label('НМЦК от')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Параметр ЕИС: priceFromGeneral'),
                TextInput::make('filter.priceTo')
                    ->label('НМЦК до')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Параметр ЕИС: priceToGeneral. Ноль запрещён — обнуляет выдачу.'),
                DatePicker::make('filter.publishedFrom')
                    ->label('Дата размещения с')
                    ->helperText(
                        'Параметр ЕИС: publishDateFrom. У профиля archive это ОКНО ПРОГОНА (запуск без '
                        .'--from в консоли берёт его отсюда) — у fresh/delta это обычный фильтр выдачи.',
                    ),
                DatePicker::make('filter.publishedTo')
                    ->label('Дата размещения по')
                    ->helperText('Параметр ЕИС: publishDateTo — та же двойная роль, что у поля «с».'),
                TextInput::make('filter.searchString')
                    ->label('Строка поиска')
                    ->helperText('Параметр ЕИС: searchString. Принимает и реестровый номер.'),
                Toggle::make('filter.morphology')
                    ->label('Морфология поиска')
                    ->helperText('Параметр ЕИС: morphology. Имеет смысл только вместе со строкой поиска.'),
                Select::make('filter.sortField')
                    ->label('Сортировка выдачи')
                    ->options(ListingSortField::class)
                    ->helperText(
                        'Параметр ЕИС: sortBy. Не выбрано — прежний дефолт (дата обновления). '
                        .'В archive/delta-прогоне направление ставит сканер и это поле не действует.',
                    ),
                Toggle::make('filter.sortAscending')
                    ->label('По возрастанию')
                    ->helperText(
                        'Параметр ЕИС: sortDirection (инвертирован площадкой — выключено означает убывание).',
                    ),
            ],
            default => [
                Placeholder::make('filter_unavailable')
                    ->label('Фильтр площадки')
                    ->content('Выберите реестр — состав полей фильтра зависит от него.'),
            ],
        };
    }

    private static function previewUrl(Get $get): string
    {
        $registryId = $get('registry_id');

        if (! is_numeric($registryId)) {
            return 'Выберите реестр, чтобы увидеть пример URL.';
        }

        $registry = Registry::find($registryId);

        if (! $registry instanceof Registry) {
            return 'Выберите реестр, чтобы увидеть пример URL.';
        }

        try {
            $filterClass = app(CrawlerFilterFactory::class)->for(registry: $registry);

            $filterState = $get('filter');
            $filter = is_array($filterState) && $filterState !== []
                ? $filterClass::validateAndCreate($filterState)
                : null;

            $columnsState = $get('options.columns');
            $columns = array_fill_keys(
                array_unique([...(is_array($columnsState) ? $columnsState : []), ...self::MANDATORY_COLUMN_FLAGS]),
                true,
            );

            $crawler = new Crawler;
            $crawler->setRelation('registry', $registry);
            $crawler->filter = $filter;
            $crawler->options = new CrawlerRuntimeOptions(columns: $columns);

            $query = app(EisCsvUrlBuilder::class)->buildQueryParams(crawler: $crawler, from: 1, to: 500);

            return (string) config('crawler.eis.csv_endpoint').'?'.http_build_query($query);
        } catch (Throwable) {
            return 'Пример недоступен: заполните фильтр валидными значениями.';
        }
    }
}
