<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Schemas;

use App\Contracts\Procurement\Crawler\CrawlerPlatformForm;
use App\Dto\Procurement\Crawler\CrawlerRuntimeOptions;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Platform\Okpd2NodeReadRepository;
use App\Services\Procurement\CrawlerFilterFactory;
use App\Services\Procurement\Eis\EisCsvUrlBuilder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Throwable;

final readonly class EisCrawlerPlatformForm implements CrawlerPlatformForm
{
    /** @var array<string, string> */
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

    /** @var list<string> */
    private const array MANDATORY_COLUMN_FLAGS = [
        'registryNumberCsv',
        'publishDateCsv',
        'lastDateChangeCsv',
    ];

    public function __construct(
        private CrawlerFilterFactory $filters,
        private Okpd2NodeReadRepository $okpd2Nodes,
        private EisCsvUrlBuilder $urlBuilder,
    ) {}

    /** @return list<Component> */
    public function components(Registry $registry, Get $get): array
    {
        return [
            Fieldset::make('Фильтр площадки')
                ->columnSpanFull()
                ->schema($this->filterFields(registry: $registry)),
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
                    return array_fill_keys(self::selectedColumns($state), true);
                }),
            Fieldset::make('Переопределения листинга')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('options.retries')->label('Попытки')->numeric()->minValue(0),
                    TextInput::make('options.retryDelayMs')->label('Пауза перед повтором, мс')->numeric()->minValue(0),
                    TextInput::make('options.minPauseMs')->label('Пауза между запросами от, мс')->numeric()->minValue(0),
                    TextInput::make('options.maxPauseMs')->label('Пауза между запросами до, мс')->numeric()->minValue(0),
                ]),
            Placeholder::make('url_preview')
                ->label('Пример URL выгрузки (from=1, to=500)')
                ->columnSpanFull()
                ->content(fn (): string => $this->previewForState(registry: $registry, get: $get)),
        ];
    }

    public function previewUrl(Crawler $crawler): string
    {
        $query = $this->urlBuilder->buildQueryParams(crawler: $crawler, from: 1, to: 500);

        return $this->configString('crawler.eis.csv_endpoint').'?'.http_build_query($query);
    }

    /** @return list<Component> */
    private function filterFields(Registry $registry): array
    {
        return [
            $this->okpd2CodeField(registry: $registry),
            Toggle::make('filter.stageApplications')->label('Этап «Подача заявок»'),
            Toggle::make('filter.stageCommission')->label('Этап «Работа комиссии»'),
            Toggle::make('filter.stageCompleted')->label('Этап «Закупка завершена»'),
            Toggle::make('filter.stageCancelled')->label('Этап «Закупка отменена»'),
            TextInput::make('filter.priceFrom')->label('НМЦК от')->numeric()->minValue(0),
            TextInput::make('filter.priceTo')->label('НМЦК до')->numeric()->minValue(1),
            DatePicker::make('filter.publishedFrom')->label('Дата размещения с'),
            DatePicker::make('filter.publishedTo')->label('Дата размещения по'),
            TextInput::make('filter.searchString')->label('Строка поиска'),
            Toggle::make('filter.morphology')->label('Морфология поиска'),
            Select::make('filter.sortField')->label('Сортировка выдачи')->options(ListingSortField::class),
            Toggle::make('filter.sortAscending')->label('По возрастанию'),
        ];
    }

    private function previewForState(Registry $registry, Get $get): string
    {
        try {
            $filterClass = $this->filters->for(registry: $registry);
            $filterState = $get('filter');
            $filter = is_array($filterState) && $filterState !== []
                ? $filterClass::validateAndCreate($filterState)
                : null;
            $columns = array_fill_keys(self::selectedColumns($get('options.columns')), true);
            $crawler = new Crawler;
            $crawler->setRelation('registry', $registry);
            $crawler->filter = $filter;
            $crawler->options = new CrawlerRuntimeOptions(columns: $columns);

            return $this->previewUrl(crawler: $crawler);
        } catch (Throwable) {
            return 'Пример недоступен: заполните фильтр валидными значениями.';
        }
    }

    private function okpd2CodeField(Registry $registry): Select|Placeholder
    {
        $nodes = $this->okpd2Nodes->selectableNodes(platformId: $registry->platform_id);

        if ($nodes->isEmpty()) {
            return Placeholder::make('filter_okpd2_empty')
                ->label('ОКПД2')
                ->content('Карта ОКПД2 этой площадки пуста — прогоните сид карты. Без карты профиль сохранить нельзя.');
        }

        return Select::make('filter.okpd2Codes')
            ->label('ОКПД2')
            ->multiple()
            ->required()
            ->searchable()
            ->options($this->okpd2CodeOptions(nodes: $nodes))
            ->dehydrateStateUsing(static fn (mixed $state): array => self::stringList($state));
    }

    /** @return array<string, array<string, string>> */
    /** @param Collection<int, Okpd2Node> $nodes
     * @return array<string, array<string, string>>
     */
    private function okpd2CodeOptions(Collection $nodes): array
    {
        $labelsByCode = $nodes->mapWithKeys(
            static fn (Okpd2Node $node): array => [self::nodeAttribute($node, 'code') => self::okpd2OptionLabel(node: $node)],
        );

        return $nodes
            ->groupBy(static fn (Okpd2Node $node): string => self::nodeAttribute($node, 'parent_code', ''))
            ->mapWithKeys(static function (Collection $group, string $parentCode) use ($labelsByCode): array {
                $groupLabel = $parentCode === '' ? 'Разделы' : ($labelsByCode->get($parentCode) ?? $parentCode);

                return [$groupLabel => $group->mapWithKeys(static fn (Okpd2Node $node): array => [
                    self::nodeAttribute($node, 'code') => self::okpd2OptionLabel(node: $node),
                ])->all()];
            })
            ->all();
    }

    private static function okpd2OptionLabel(Okpd2Node $node): string
    {
        return sprintf('%s — %s', self::nodeAttribute($node, 'code'), self::nodeAttribute($node, 'name'));
    }

    /**
     * @return list<string>
     */
    private static function selectedColumns(mixed $state): array
    {
        $selected = [];

        if (is_array($state)) {
            foreach ($state as $key => $value) {
                $column = is_string($key) && $value === true ? $key : $value;

                if (is_string($column) && isset(self::COLUMN_FLAG_LABELS[$column])) {
                    $selected[] = $column;
                }
            }
        }

        return array_values(array_unique([...$selected, ...self::MANDATORY_COLUMN_FLAGS]));
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    private static function nodeAttribute(Okpd2Node $node, string $attribute, ?string $default = null): string
    {
        $value = $node->getAttribute($attribute);

        if ($value === null && $default !== null) {
            return $default;
        }

        if (! is_string($value)) {
            throw new \LogicException("OKPD2 {$attribute} должен быть строкой.");
        }

        return $value;
    }

    private function configString(string $key): string
    {
        $value = config($key);

        if (! is_string($value)) {
            throw new \LogicException("{$key} должен быть строкой.");
        }

        return $value;
    }
}
