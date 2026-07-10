<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use App\Enums\Procurement\DiscoveryMarker;
use Spatie\LaravelData\Data;

/**
 * Одна маркерная запись discovery-окна (docs/specs/v2/08-estimate-extraction.md §3.4, D30/D37).
 * - page: номер физической страницы документа, к которой относится маркер.
 * - marker: estimate_start (первая страница новой сметы в окне) / continuation (продолжение
 *   текущей сметы) / estimate_end (последняя страница текущей сметы в окне).
 * - title: заголовок/название сметы, если виден на этой странице (только для estimate_start).
 */
final class DiscoveryMarkerLine extends Data
{
    public function __construct(
        public int $page,
        public DiscoveryMarker $marker,
        public ?string $title = null,
    ) {}
}
