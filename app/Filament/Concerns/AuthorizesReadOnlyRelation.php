<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use AzGuard\Filament\Concerns\AuthorizesRelationManager;
use Filament\Resources\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Связанные записи читаются по тем же грантам, что и карточка владельца.
 *
 * @phpstan-require-extends RelationManager
 */
trait AuthorizesReadOnlyRelation
{
    use AuthorizesRelationManager;

    public function mountAuthorizesReadOnlyRelation(): void
    {
        $this->authorizeRelationOwner();
    }

    public function hydrateAuthorizesReadOnlyRelation(): void
    {
        $this->authorizeRelationOwner();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    private function authorizeRelationOwner(): void
    {
        $owner = $this->getOwnerRecord();
        $page = $this->getPageClass();
        abort_unless(is_subclass_of($page, Page::class), 403);

        $resource = $page::getResource();

        abort_unless(
            static::canViewForRecord(ownerRecord: $owner, pageClass: $page)
                && $resource::canView($owner),
            403,
        );

        /** @var Builder<Model> $ownerQuery */
        $ownerQuery = $resource::getEloquentQuery();
        abort_unless($ownerQuery->whereKey($owner->getKey())->exists(), 403);
    }
}
