<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Commands\Platform\Concerns\InteractsWithParsingRules;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use App\Services\Procurement\Parsing\Healing\ParsingRuleAutoHealer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'parsing-rules:auto-heal')]
#[Signature('parsing-rules:auto-heal {platform} {stage} {--sample=} {--part=} {--regression=*} {--registry=} {--scope=default} {--activate}')]
#[Description('Discover structural auto-heal candidate rules from a broken sample and verify against regression fixtures')]
final class AutoHealParsingRulesCommand extends Command
{
    use InteractsWithParsingRules;

    public function handle(
        ParsingRuleReadRepository $readRepository,
        ParsingRuleAutoHealer $autoHealer,
    ): int {
        $coords = $this->resolveTargetCoordinates();
        if ($coords === null) {
            return self::INVALID;
        }

        $sampleOption = $this->option('sample');
        if (! is_string($sampleOption) || trim($sampleOption) === '') {
            $this->error('Опция --sample= обязательна для auto-heal.');

            return self::INVALID;
        }

        $samplePath = trim($sampleOption);
        if (! is_file($samplePath) || ! is_readable($samplePath)) {
            $this->error(sprintf('Файл образца "%s" не найден или недоступен.', $samplePath));

            return self::FAILURE;
        }

        $brokenSample = file_get_contents($samplePath);
        if (! is_string($brokenSample)) {
            $this->error(sprintf('Не удалось прочитать файл "%s".', $samplePath));

            return self::FAILURE;
        }

        $partOption = $this->option('part');
        $part = is_string($partOption) && trim($partOption) !== '' ? trim($partOption) : null;

        $regressions = $this->loadSampleFiles($this->option('regression'), $part);
        if ($regressions === null) {
            return self::FAILURE;
        }

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

        $candidate = $autoHealer->discoverCandidates(
            ruleSet: $ruleSet,
            brokenSample: $brokenSample,
            part: $part,
            regressionFixtures: $regressions,
            autoActivate: (bool) $this->option('activate'),
            actorType: ParsingRuleActorType::Cli,
        );

        if ($candidate === null) {
            $this->warn('Кандидат auto-heal не предложен (нет изменений, дубликат или не прошёл dry-run).');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Создан кандидат v%d (id=%d, status=%s).',
            $candidate->version,
            $candidate->id,
            $candidate->status->value,
        ));

        return self::SUCCESS;
    }
}
