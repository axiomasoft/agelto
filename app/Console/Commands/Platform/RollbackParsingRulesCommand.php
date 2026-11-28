<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Commands\Platform\Concerns\InteractsWithParsingRules;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'parsing-rules:rollback')]
#[Signature('parsing-rules:rollback {platform} {stage} {--to=} {--registry=} {--scope=default} {--reason=}')]
#[Description('Rollback active parsing rules version to a specified target version number')]
final class RollbackParsingRulesCommand extends Command
{
    use InteractsWithParsingRules;

    public function handle(
        ParsingRuleReadRepository $readRepository,
        ParsingRuleManager $manager,
    ): int {
        $coords = $this->resolveTargetCoordinates();
        if ($coords === null) {
            return self::INVALID;
        }

        $toOption = $this->option('to');
        if (! is_string($toOption) || ! ctype_digit(trim($toOption))) {
            $this->error('Опция --to= должна содержать номер целевой версии.');

            return self::INVALID;
        }

        $targetVersionNumber = (int) trim($toOption);
        $ruleSet = $this->findRuleSetOrError(
            $readRepository,
            $coords['platform'],
            $coords['stage'],
            $coords['registry'],
            $coords['scope'],
        );
        if ($ruleSet === null) {
            return self::FAILURE;
        }

        $targetVersion = $readRepository->findVersionByNumber($ruleSet->id, $targetVersionNumber);
        if ($targetVersion === null) {
            $this->error(sprintf('Версия v%d не найдена в наборе #%d.', $targetVersionNumber, $ruleSet->id));

            return self::FAILURE;
        }

        $reasonOption = $this->option('reason');
        $reason = is_string($reasonOption) && trim($reasonOption) !== ''
            ? trim($reasonOption)
            : sprintf('CLI rollback to v%d', $targetVersionNumber);

        $activated = $manager->rollback(
            ruleSetId: $ruleSet->id,
            targetVersionId: $targetVersion->id,
            actorType: ParsingRuleActorType::Cli,
            actorId: null,
            reason: $reason,
        );

        $this->info(sprintf('Выполнен откат: активна версия v%d (id=%d).', $activated->version, $activated->id));

        return self::SUCCESS;
    }
}
