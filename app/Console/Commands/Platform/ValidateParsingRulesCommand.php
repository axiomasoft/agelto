<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Commands\Platform\Concerns\InteractsWithParsingRules;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use App\Services\Procurement\Parsing\Healing\ParsingRuleDryRunVerifier;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use App\Services\Procurement\Parsing\Validation\ParsingRuleValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'parsing-rules:validate')]
#[Signature('parsing-rules:validate {platform} {stage} {--file=} {--rule-version=} {--registry=} {--scope=default} {--sample=*} {--part=}')]
#[Description('Validate parsing rules JSON or stored version against schema and optional dry-run samples')]
final class ValidateParsingRulesCommand extends Command
{
    use InteractsWithParsingRules;

    public function handle(
        ParsingRuleValidator $validator,
        ParsingRuleReadRepository $readRepository,
        ParsingRuleDryRunVerifier $verifier,
    ): int {
        $coords = $this->resolveTargetCoordinates();
        if ($coords === null) {
            return self::INVALID;
        }

        $fileOption = $this->option('file');
        $versionOption = $this->option('rule-version');
        $schemaVersion = 1;

        if (is_string($fileOption) && trim($fileOption) !== '') {
            $rules = $this->readJsonRulesFile(trim($fileOption));
            if ($rules === null) {
                return self::FAILURE;
            }
        } else {
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

            $version = is_string($versionOption) && trim($versionOption) !== ''
                ? $readRepository->findVersionByNumber($ruleSet->id, (int) $versionOption)
                : ($ruleSet->active_version_id !== null ? $readRepository->findVersionPayloadById($ruleSet->active_version_id) : null);

            if ($version === null) {
                $this->error('Целевая версия правил не найдена.');

                return self::FAILURE;
            }

            $rules = $version->rules_json;
            $schemaVersion = $version->schema_version;
        }

        $validation = app(ParsingRuleManager::class)->validate(
            stage: $coords['stage'],
            rules: $rules,
            schemaVersion: $schemaVersion,
            platformCode: $coords['platform']->code,
        );
        if (! $validation->valid) {
            $this->error(sprintf('Валидация схемы не пройдена (%s):', $validation->schemaId));
            foreach ($validation->issues as $issue) {
                $this->line(sprintf(' - [%s] %s: %s', $issue->severity, $issue->path, $issue->message));
            }

            return self::FAILURE;
        }

        $this->info(sprintf('Схема %s v%d: валидна.', $validation->schemaId, $validation->schemaVersion));

        $partOption = $this->option('part');
        $samples = $this->loadSampleFiles($this->option('sample'), is_string($partOption) ? $partOption : null);
        if ($samples === null) {
            return self::FAILURE;
        }

        if ($samples !== []) {
            $dto = $validator->hydrateDto($coords['stage'], $rules);
            $report = $verifier->verify($dto, $samples);

            foreach ($report->samples as $sampleReport) {
                $status = $sampleReport->passed ? 'OK' : 'FAIL';
                $this->line(sprintf(' [%s] %s (extracted=%d)', $status, $sampleReport->sampleKey, $sampleReport->extractedCount));
                foreach ($sampleReport->errors as $error) {
                    $this->error('   '.$error);
                }
            }

            if (! $report->passed) {
                $this->error(sprintf('Dry-run не пройден (%d/%d).', $report->passedCount, $report->samplesCount));

                return self::FAILURE;
            }

            $this->info(sprintf('Dry-run пройден (%d/%d).', $report->passedCount, $report->samplesCount));
        }

        return self::SUCCESS;
    }
}
