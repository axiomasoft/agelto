<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Card;

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Exceptions\Platforms\Eis\CardUrlTemplateMissingException;

/** Pure typed card route catalogue; no mutable registry settings lookup. */
final readonly class EisCardUrlBuilder
{
    private const string REG_NUMBER_PLACEHOLDER = '{regNumber}';

    public function __construct(private EisCardRoutes $routes) {}

    /**
     * @return non-empty-string сегмент типа процедуры в URL карточки (`ea20`/`zk20`/…)
     */
    public function resolveProcedureUrlType(string $registry, ?string $procedureTypeRaw): string
    {
        /** @var array<string, string> $map */
        $map = $this->routes->procedureTypes;
        $procedureUrlType = $procedureTypeRaw !== null ? ($map[$procedureTypeRaw] ?? null) : null;

        if (! is_string($procedureUrlType) || $procedureUrlType === '') {
            throw CardUrlTemplateMissingException::forUnknownProcedureType(
                registryCode: $registry,
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
    public function buildUrl(string $registry, string $procedureUrlType, string $tab, string $registryNumber): ?string
    {
        /** @var array<string, array<string, ?string>> $templates */
        $templates = $this->routes->procedures;

        if (! array_key_exists($procedureUrlType, $templates) || ! array_key_exists($tab, $templates[$procedureUrlType])) {
            throw CardUrlTemplateMissingException::forMissingTabTemplate(
                registryCode: $registry,
                procedureUrlType: $procedureUrlType,
                tab: $tab,
            );
        }

        $template = $templates[$procedureUrlType][$tab];

        return $template === null ? null : str_replace(self::REG_NUMBER_PLACEHOLDER, rawurlencode($registryNumber), $template);
    }
}
