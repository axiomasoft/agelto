<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Commands\Platform\Concerns\InteractsWithParsingRules;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use App\Services\Procurement\Parsing\Schema\ParsingRuleSchemaRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'parsing-rules:export')]
#[Signature('parsing-rules:export {platform} {stage} {--rule-version=} {--registry=} {--scope=default} {--schema}')]
#[Description('Export parsing rules JSON or JSON Schema to stdout')]
final class ExportParsingRulesCommand extends Command
{
    use InteractsWithParsingRules;

    public function handle(
        ParsingRuleReadRepository $readRepository,
        ParsingRuleSchemaRegistry $schemaRegistry,
    ): int {
        $coords = $this->resolveTargetCoordinates();
        if ($coords === null) {
            return self::INVALID;
        }

        if ((bool) $this->option('schema')) {
            $schema = $schemaRegistry->loadSchemaObject($coords['stage']);
            $encoded = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($encoded)) {
                $this->error('Не удалось сериализовать JSON Schema.');

                return self::FAILURE;
            }
            $this->line($encoded);

            return self::SUCCESS;
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

        $versionOption = $this->option('rule-version');
        $version = is_string($versionOption) && trim($versionOption) !== ''
            ? $readRepository->findVersionByNumber($ruleSet->id, (int) $versionOption)
            : ($ruleSet->active_version_id !== null ? $readRepository->findVersionPayloadById($ruleSet->active_version_id) : null);

        if ($version === null) {
            $this->error('Версия правил для экспорта не найдена.');

            return self::FAILURE;
        }

        $encoded = json_encode($version->rules_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            $this->error('Не удалось сериализовать правила.');

            return self::FAILURE;
        }

        $this->line($encoded);

        return self::SUCCESS;
    }
}
