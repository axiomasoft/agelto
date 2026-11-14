<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Services\Procurement\Proxyma\UiAuthority;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Proxyma\Application\Catalog\CatalogConflict;

/** Сетевая часть старой owner формы не переживает смену selector. */
trait GuardsNetworkSettingsForm
{
    #[Locked]
    public ?string $networkAuthorityVersion = null;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->networkAuthorityVersion = app(UiAuthority::class)->version();

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $version = $this->networkAuthorityVersion;
            $engine = str_starts_with($version ?? '', 'proxyma:') ? 'proxyma' : 'legacy';
            try {
                app(UiAuthority::class)->assertLocked(DB::connection(), $engine, $version);
            } catch (CatalogConflict) {
                throw ValidationException::withMessages(['data' => 'Источник маршрутов изменён. Перезагрузите форму.']);
            }

            return parent::handleRecordUpdate($record, $data);
        });
    }
}
