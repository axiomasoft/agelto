<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Exceptions\CardUrlTemplateMissingException;

/**
 * URL детальной карточки закупки ЕИС (`/epz/order/notice/<тип>/view/<вкладка>.html?regNumber=…`,
 * `research/eis-card-recon.md` §1). `<тип>` (`ea20`/`zk20`/`ezt20`/…) зависит от способа закупки
 * и резолвится по `Registry->settings['procedure_url_types']`; сами URL-шаблоны по типу+вкладке —
 * `Registry->settings['card_url_templates']`. Ничего не хардкодится в коде — площадка настраивает
 * оба справочника через `prc_registries.settings` (D10 — ЕИС-специфика только здесь).
 */
final readonly class EisCardUrlBuilder
{
    private const string REG_NUMBER_PLACEHOLDER = '{regNumber}';

    /**
     * @return non-empty-string сегмент типа процедуры в URL карточки (`ea20`/`zk20`/…)
     */
    public function resolveProcedureUrlType(Registry $registry, ?string $procedureTypeRaw): string
    {
        /** @var array<string, string> $map */
        $map = $registry->settings['procedure_url_types'] ?? [];
        $procedureUrlType = $procedureTypeRaw !== null ? ($map[$procedureTypeRaw] ?? null) : null;

        if (! is_string($procedureUrlType) || $procedureUrlType === '') {
            throw CardUrlTemplateMissingException::forUnknownProcedureType(
                registryCode: $registry->code,
                procedureType: $procedureTypeRaw,
            );
        }

        return $procedureUrlType;
    }

    /**
     * `null` в шаблоне (`array_key_exists` истинен, значение `null`) — явный маркер D121
     * «у этого типа процедуры такой вкладки нет по природе», разбор карточки продолжается.
     * Отсутствие самого ключа — незаполненный справочник, остаётся ошибкой конфигурации.
     */
    public function buildUrl(Registry $registry, string $procedureUrlType, string $tab, string $registryNumber): ?string
    {
        /** @var array<string, array<string, ?string>> $templates */
        $templates = $registry->settings['card_url_templates'] ?? [];

        if (! array_key_exists($procedureUrlType, $templates) || ! array_key_exists($tab, $templates[$procedureUrlType])) {
            throw CardUrlTemplateMissingException::forMissingTabTemplate(
                registryCode: $registry->code,
                procedureUrlType: $procedureUrlType,
                tab: $tab,
            );
        }

        $template = $templates[$procedureUrlType][$tab];

        return $template === null ? null : str_replace(self::REG_NUMBER_PLACEHOLDER, $registryNumber, $template);
    }
}
