<?php

declare(strict_types=1);

namespace App\Filament\Platforms\Eis;

use App\Contracts\Procurement\Platform\PlatformUiExtension;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final readonly class EisUiExtension implements PlatformUiExtension
{
    public function profileFormSchema(): array
    {
        return [Fieldset::make('ЕИС')->schema(static function (Get $get): array {
            $id = $get('registry_id');
            $registry = is_numeric($id) ? Registry::query()->find((int) $id) : null;

            return $registry === null ? [] : app(EisCrawlerPlatformForm::class)->components($registry);
        })];
    }

    public function procurementInfolistSections(Procurement $procurement): array
    {
        $details = EisDetails::query()->find($procurement->id);

        return [Section::make('ЕИС')->schema([
            TextEntry::make('eis.ikz')->label('ИКЗ')->state($details?->getAttribute('ikz'))->placeholder('—'),
            TextEntry::make('eis.etp_number')->label('Номер ЭТП')->state($details?->getAttribute('etp_number'))->placeholder('—'),
            TextEntry::make('eis.registry_guid')->label('GUID в реестре')->state($details?->getAttribute('registry_guid'))->placeholder('—'),
        ])];
    }
}
