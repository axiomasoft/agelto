<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use App\Guards\Admin\Permissions\ProxyPermission;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Proxyma\TargetLink;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Filament\Actions\ConfigureProxymaAction;

final readonly class SettingsSections
{
    public function __construct(private UiAuthority $authority) {}

    /** @return list<Component> */
    public function forRecord(?Model $record): array
    {
        if (! $this->authority->active('proxyma')) {
            return [TextEntry::make('legacy_authority')->hiddenLabel()->state('До переключения маршруты редактируются в действующем legacy каталоге.')];
        }
        if (! $record?->exists || (! $record instanceof Crawler && ! $record instanceof Platform)) {
            return [TextEntry::make('save_first')->hiddenLabel()->state('Сначала сохраните запись владельца.')];
        }
        if (! (Auth::user()?->hasPermission(permission: ProxyPermission::View, panelId: 'admin') ?? false)) {
            return [];
        }
        $id = $record->getKey();
        if (! is_int($id) && ! is_string($id)) {
            throw new \LogicException('Invalid settings owner identity.');
        }
        $platform = $record instanceof Crawler ? $record->registry?->platform_id : $id;
        $subject = new SubjectRef(TenantKey::system(), $record instanceof Crawler ? 'crawler' : 'platform', (string) $id);
        $targets = DB::table((new TargetLink)->getTable().' as l')->join('prc_source_targets as s', 's.id', '=', 'l.source_target_id')
            ->where('l.tenant_key', TenantKey::SYSTEM)->where('s.platform_id', $platform)->whereNull('s.retired_at')
            ->whereColumn('l.source_revision', 's.policy_version')->orderBy('s.target_key')->limit(64)->get(['l.target_id', 's.capability', 's.target_key']);
        $actions = [];
        foreach ($targets as $target) {
            if (! is_string($target->target_id) || ! is_string($target->target_key) || ! is_string($target->capability)) {
                throw new \LogicException('Invalid target mapping projection.');
            }
            $slots = match ($target->capability) {
                'listing' => ['agelto.acquisition', ['listing']],
                'detail' => ['agelto.acquisition', ['detail', 'status_refresh']],
                'download' => ['agelto.document', ['download']],
                'reference_sync' => ['agelto.reference', ['reference']],
                default => null,
            };
            if ($slots === null) {
                continue;
            }
            foreach ($slots[1] as $slot) {
                $actions[] = ConfigureProxymaAction::make(fn (): SubjectRef => $subject, $slots[0], $slot,
                    new Uuid($target->target_id), 'proxyma_'.str_replace('-', '', $target->target_id).'_'.$slot)
                    ->label($target->target_key.' · '.$slot);
            }
        }

        return $actions === [] ? [TextEntry::make('unmapped')->hiddenLabel()->state('Нет актуального target mapping. Настройки недоступны.')]
            : [Actions::make($actions)];
    }
}
