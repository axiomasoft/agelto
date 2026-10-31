<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

use App\Dto\Procurement\Platform\CollectionGuard;
use App\Dto\Procurement\Process\ContentHash;
use App\Services\Procurement\Platform\Contracts\ListingFilter;

/** IDs are resolved from the platform reference catalogue before fetching; no DB in a parser. */
final readonly class EisListingFilter implements ListingFilter
{
    /**
     * @param  list<string>  $okpd2Ids
     * @param  list<string>  $stages
     */
    public function __construct(public array $okpd2Ids, public array $stages = [], public ?string $search = null,
        public bool $morphology = false, public string $sortBy = 'UPDATE_DATE')
    {
        CollectionGuard::check($okpd2Ids, 'string', 256);
        CollectionGuard::check($stages, 'string', 4);
        if (! in_array($sortBy, ['UPDATE_DATE', 'PUBLISH_DATE'], true)) {
            throw new \InvalidArgumentException('Invalid EIS sort field.');
        }
        if ($okpd2Ids === [] || array_diff($stages, ['af', 'ca', 'pc', 'pa']) !== []) {
            throw new \InvalidArgumentException('Invalid EIS filter scope.');
        }
        foreach ($okpd2Ids as $id) {
            if (! preg_match('/^[0-9]{1,20}$/D', $id)) {
                throw new \InvalidArgumentException('Unresolved EIS classifier ID.');
            }
        }
        if ($search !== null && (strlen($search) > 1000 || str_contains($search, "\0"))) {
            throw new \InvalidArgumentException('Invalid EIS search.');
        }
    }

    /** @return array<string, string> */
    public function query(): array
    {
        $ids = $this->okpd2Ids;
        sort($ids, SORT_STRING);
        $query = ['okpd2Ids' => implode(',', array_unique($ids)), 'okpd2IdsWithNested' => 'on'];
        foreach ($this->stages as $stage) {
            $query[$stage] = 'on';
        }
        if ($this->search !== null) {
            $query['searchString'] = $this->search;
            if ($this->morphology) {
                $query['morphology'] = 'on';
            }
        }
        if ($this->sortBy !== 'UPDATE_DATE') {
            $query['sortBy'] = $this->sortBy;
        }
        ksort($query, SORT_STRING);

        return $query;
    }

    public function fingerprint(): ContentHash
    {
        return ContentHash::of(json_encode($this->query(), JSON_THROW_ON_ERROR));
    }

    public function acceptsStage(string $stage): bool
    {
        if ($this->stages === []) {
            return true;
        }
        $code = match ($stage) {
            'Подача заявок' => 'af',
            'Работа комиссии' => 'ca',
            'Закупка завершена', 'Определение поставщика завершено' => 'pc',
            'Закупка отменена' => 'pa',
            default => null,
        };

        return in_array($code, $this->stages, true);
    }
}
