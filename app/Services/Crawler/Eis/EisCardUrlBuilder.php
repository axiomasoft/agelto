<?php

declare(strict_types=1);

namespace App\Services\Crawler\Eis;

use App\Models\Platform\Registry;
use App\Services\Crawler\Exceptions\CardUrlTemplateMissingException;

/**
 * URL детальной карточки закупки ЕИС (`/epz/order/notice/<тип>/view/<вкладка>.html?regNumber=…`,
 * `research/eis-card-recon.md` §1). `<тип>` (`ea20`/`zk20`/`ezt20`/…) зависит от способа закупки
 * и резолвится по `Registry->settings['procedure_url_types']`; сами URL-шаблоны по типу+вкладке —
 * `Registry->settings['card_url_templates']`. Ничего не хардкодится в коде — площадка настраивает
 * оба справочника через `plt_registries.settings` (D10 — ЕИС-специфика только здесь).
 */
final readonly class EisCardUrlBuilder
{
    private const string REG_NUMBER_PLACEHOLDER = '{regNumber}';

    /**
     * @return non-empty-string сегмент типа процедуры в URL карточки (`ea20`/`zk20`/…)
     */
    public function resolveProcedureUrlType(Registry $registry, ?string $procedureType): string
    {
        /** @var array<string, string> $map */
        $map = $registry->settings['procedure_url_types'] ?? [];
        $procedureUrlType = $procedureType !== null ? ($map[$procedureType] ?? null) : null;

        if ($procedureUrlType === null) {
            throw CardUrlTemplateMissingException::forUnknownProcedureType(
                registryCode: $registry->code,
                procedureType: $procedureType,
            );
        }

        return $procedureUrlType;
    }

    public function buildUrl(Registry $registry, string $procedureUrlType, string $tab, string $sourceNumber): string
    {
        /** @var array<string, array<string, string>> $templates */
        $templates = $registry->settings['card_url_templates'] ?? [];
        $template = $templates[$procedureUrlType][$tab] ?? null;

        if ($template === null) {
            throw CardUrlTemplateMissingException::forMissingTabTemplate(
                registryCode: $registry->code,
                procedureUrlType: $procedureUrlType,
                tab: $tab,
            );
        }

        return str_replace(self::REG_NUMBER_PLACEHOLDER, $sourceNumber, $template);
    }
}
