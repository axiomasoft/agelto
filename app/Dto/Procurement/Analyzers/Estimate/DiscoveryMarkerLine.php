<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Description;
use App\Enums\Procurement\DiscoveryMarker;
use Spatie\LaravelData\Data;

/**
 * Одна маркерная запись discovery-окна (docs/specs/v2/08-estimate-extraction.md §3.4).
 */
final class DiscoveryMarkerLine extends Data
{
    public function __construct(
        #[Description('номер физической страницы документа, к которой относится маркер.')]
        public int $page,
        #[Description('estimate_start (первая страница новой сметы в окне) / continuation (продолжение текущей сметы) / estimate_end (последняя страница текущей сметы в окне).')]
        public DiscoveryMarker $marker,
        #[Description('заголовок/название сметы, если виден на этой странице (только для estimate_start).')]
        public ?string $title = null,
    ) {}
}
