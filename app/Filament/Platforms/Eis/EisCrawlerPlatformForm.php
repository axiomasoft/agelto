<?php

declare(strict_types=1);

namespace App\Filament\Platforms\Eis;

use App\Dto\Platforms\Eis\EisPolicy;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Platform\Okpd2NodeReadRepository;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Support\Collection;

final readonly class EisCrawlerPlatformForm
{
    public function __construct(private Okpd2NodeReadRepository $okpd2Nodes) {}

    /** @return list<Component> */
    public function components(Registry $registry): array
    {
        return [Fieldset::make('Фильтр площадки')->columnSpanFull()->schema($this->filterFields($registry))];
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
            self::sourceDateField('filter.publishedFrom', 'Дата размещения с'),
            self::sourceDateField('filter.publishedTo', 'Дата размещения по'),
            TextInput::make('filter.searchString')->label('Строка поиска'),
            Toggle::make('filter.morphology')->label('Морфология поиска'),
            Select::make('filter.sortField')->label('Сортировка выдачи')->options(ListingSortField::class),
            Toggle::make('filter.sortAscending')->label('По возрастанию'),
        ];
    }

    private static function sourceDateField(string $name, string $label): DatePicker
    {
        $timezone = app(EisPolicy::class)->sourceTimezone->getName();

        // Date-only state не должен терять день источника или получать текущее время при DTO cast.
        return DatePicker::make($name)->label($label)->timezone($timezone)->format(DATE_ATOM)
            ->dehydrateStateUsing(static fn (?string $state): ?string => $state === null ? null
                : CarbonImmutable::parse($state)->setTimezone($timezone)->startOfDay()->toAtomString());
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
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (is_string($entry) || is_int($entry)) {
                $strings[] = (string) $entry;
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
}
