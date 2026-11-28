<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Extraction;

use App\Dto\Procurement\Parsing\Rules\StageParsingRules;
use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Process\Core\StageCode;

/**
 * Разрешённый к исполнению активный набор правил парсинга с метаданными версии.
 */
final readonly class ResolvedParsingRuleSet
{
    public function __construct(
        public int $ruleSetId,
        public int $ruleVersionId,
        public int $version,
        public string $platformCode,
        public StageCode $stage,
        public ?string $registryCode,
        public string $scopeKey,
        public string $schemaId,
        public int $schemaVersion,
        public string $rulesHashHex,
        public AutoHealMode $autoHealMode,
        public StageParsingRules $rules,
    ) {}
}
