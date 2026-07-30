<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Prepare\Gsfx;

use Spatie\LaravelData\Data;

/**
 * Коэффициент из `Data.xml` (P3.9, `<Koefficients><K>`) — позиционный (`Position/Koefficients/K`,
 * связь с позицией выражена ВЛОЖЕННОСТЬЮ) или документный (`Document/Koefficients/K`, глобальный
 * список без ссылки на позицию — `Options="InPos …"` это флаг применимости, не ID). Родов значений
 * ЧЕТЫРЕ (`Value_OZ`/`Value_EM`/`Value_ZM`/`Value_MT`) — расхождение в пользу источника против
 * P1.4, formулировавшего три (findings/P3-gsfx-reader-gaps-2026-07-27.md §6). Ридер несёт атрибуты
 * источника ИМЕНАМИ ИСТОЧНИКА как есть; раскладку по `RowModifier` (`value_oz → $value_labor`,
 * `value_em → $value_machines`, `value_zm → $value_machinists_wage`, `value_mt → $value_materials`,
 * `level → $level`) делает маппер P5.1 (research/16-gsfx-reader-reopen.md §2).
 */
final class GsfxCoefficient extends Data
{
    public function __construct(
        public string $caption,
        public ?string $code = null,
        public ?string $options = null,
        public ?string $value_oz = null,
        public ?string $value_em = null,
        public ?string $value_zm = null,
        public ?string $value_mt = null,
        public ?string $level = null,
        public ?string $all_vid_rabs = null,
        public ?string $vr_groups_links = null,
    ) {}
}
