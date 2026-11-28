<?php

declare(strict_types=1);

namespace Database\Seeders\Platforms\Eis;

use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use App\Services\Platforms\Eis\EisDefaultParsingRules;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use Illuminate\Database\Seeder;

/**
 * Идемпотентное сидирование активных правил парсинга v1 для ЕИС (`fz44`):
 * `listing` и `detail`.
 */
final class EisParsingRulesSeeder extends Seeder
{
    private const string REGISTRY_CODE = 'fz44';

    public function __construct(
        private readonly ParsingRuleManager $manager,
        private readonly ParsingRuleReadRepository $readRepository,
    ) {}

    public function run(): void
    {
        $platformCode = new PlatformCode('eis');
        $platform = Platform::query()->where('code', $platformCode->value)->first();
        if ($platform === null) {
            return;
        }

        $this->seedStageIfMissing(
            platformId: (int) $platform->id,
            stage: StageCode::Listing,
            rules: EisDefaultParsingRules::listing()->toArray(),
            summary: 'Инициализация дефолтных правил листинга ЕИС (44-ФЗ)',
        );

        $this->seedStageIfMissing(
            platformId: (int) $platform->id,
            stage: StageCode::Detail,
            rules: EisDefaultParsingRules::detail()->toArray(),
            summary: 'Инициализация дефолтных правил карточки ЕИС (44-ФЗ)',
        );
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function seedStageIfMissing(
        int $platformId,
        StageCode $stage,
        array $rules,
        string $summary,
    ): void {
        $pointer = $this->readRepository->findActivePointer(
            platformId: $platformId,
            stage: $stage,
            registryCode: self::REGISTRY_CODE,
        );

        if ($pointer !== null && $pointer['active_version_id'] !== null) {
            return;
        }

        $this->manager->publish(
            platformId: $platformId,
            stage: $stage,
            rules: $rules,
            changeSummary: $summary,
            actorType: ParsingRuleActorType::System,
            registryCode: self::REGISTRY_CODE,
            source: ParsingRuleSource::Seed,
        );
    }
}
