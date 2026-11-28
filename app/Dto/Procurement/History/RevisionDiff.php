<?php

declare(strict_types=1);

namespace App\Dto\Procurement\History;

use Spatie\LaravelData\Data;

final class RevisionDiff extends Data
{
    /** @var array<string, string> */
    public const array FIELD_LABELS = [
        'title' => 'Наименование',
        'status' => 'Статус',
        'initial_price' => 'Начальная цена',
        'currency' => 'Валюта',
        'published_at' => 'Дата публикации',
        'source_updated_at' => 'Дата изменения на площадке',
        'bidding_starts_at' => 'Начало подачи заявок',
        'bidding_ends_at' => 'Окончание подачи заявок',
        'summary_at' => 'Дата подведения итогов',
    ];

    /** @param list<array{key: string, label: string, old: mixed, new: mixed}> $changes */
    public function __construct(public array $changes) {}

    public static function labelFor(string $key): string
    {
        return self::FIELD_LABELS[$key] ?? $key;
    }
}
