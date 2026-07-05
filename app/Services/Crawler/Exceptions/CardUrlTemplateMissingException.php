<?php

declare(strict_types=1);

namespace App\Services\Crawler\Exceptions;

use RuntimeException;

/**
 * Реестр (`plt_registries.settings`) не содержит данных, нужных `EisCardUrlBuilder`
 * для построения URL вкладки карточки — резолва типа процедуры или самого
 * URL-шаблона. Молчаливо пропускать закупку нельзя (`02-crawling.md` §5.1):
 * без URL ни одна вкладка карточки не будет собрана.
 */
final class CardUrlTemplateMissingException extends RuntimeException
{
    public static function forUnknownProcedureType(string $registryCode, ?string $procedureType): self
    {
        $label = $procedureType ?? 'null';

        return new self(
            message: "Registry [{$registryCode}] has no card URL type mapping for procedure_type [{$label}] "
                ."in settings['procedure_url_types'].",
        );
    }

    public static function forMissingTabTemplate(string $registryCode, string $procedureUrlType, string $tab): self
    {
        return new self(
            message: "Registry [{$registryCode}] has no card URL template for procedureUrlType [{$procedureUrlType}] "
                ."and tab [{$tab}] in settings['card_url_templates'].",
        );
    }
}
