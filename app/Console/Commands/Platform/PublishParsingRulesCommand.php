<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Commands\Platform\Concerns\InteractsWithParsingRules;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Exceptions\Procurement\Parsing\InvalidParsingRuleSetException;
use App\Services\Procurement\Parsing\Healing\ParsingRuleDryRunVerifier;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use App\Services\Procurement\Parsing\Validation\ParsingRuleValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'parsing-rules:publish')]
#[Signature('parsing-rules:publish {platform} {stage} {--file=} {--registry=} {--scope=default} {--reason=} {--draft} {--sample=*} {--part=}')]
#[Description('Publish or save draft parsing rules version from JSON file')]
final class PublishParsingRulesCommand extends Command
{
    use InteractsWithParsingRules;

    public function handle(
        ParsingRuleManager $manager,
        ParsingRuleValidator $validator,
        ParsingRuleDryRunVerifier $verifier,
    ): int {
        $coords = $this->resolveTargetCoordinates();
        if ($coords === null) {
            return self::INVALID;
        }

        $fileOption = $this->option('file');
        if (! is_string($fileOption) || trim($fileOption) === '') {
            $this->error('Опция --file= обязательна для публикации.');

            return self::INVALID;
        }

        $rules = $this->readJsonRulesFile(trim($fileOption));
        if ($rules === null) {
            return self::FAILURE;
        }

        $partOption = $this->option('part');
        $samples = $this->loadSampleFiles($this->option('sample'), is_string($partOption) ? $partOption : null);
        if ($samples === null) {
            return self::FAILURE;
        }

        $reasonOption = $this->option('reason');
        $reason = is_string($reasonOption) && trim($reasonOption) !== ''
            ? trim($reasonOption)
            : 'CLI publish from '.basename(trim($fileOption));

        $isDraft = (bool) $this->option('draft');

        try {
            if ($samples !== []) {
                $validation = $manager->validate(
                    stage: $coords['stage'],
                    rules: $rules,
                    platformCode: $coords['platform']->code,
                );
                if (! $validation->valid) {
                    $this->error('Валидация правил не пройдена.');

                    return self::FAILURE;
                }

                $dto = $validator->hydrateDto($coords['stage'], $rules);
                $report = $verifier->verify($dto, $samples);
                if (! $report->passed) {
                    $this->error(sprintf('Dry-run не пройден (%d/%d), публикация отменена.', $report->passedCount, $report->samplesCount));

                    return self::FAILURE;
                }
            }

            if ($isDraft) {
                $version = $manager->createDraft(
                    platformId: $coords['platform']->id,
                    stage: $coords['stage'],
                    rules: $rules,
                    changeSummary: $reason,
                    registryCode: $coords['registry'],
                    scopeKey: $coords['scope'],
                    source: ParsingRuleSource::Import,
                    actorType: ParsingRuleActorType::Cli,
                );
                $this->info(sprintf('Создан черновик версии v%d (id=%d).', $version->version, $version->id));
            } else {
                $version = $manager->publish(
                    platformId: $coords['platform']->id,
                    stage: $coords['stage'],
                    rules: $rules,
                    changeSummary: $reason,
                    actorType: ParsingRuleActorType::Cli,
                    registryCode: $coords['registry'],
                    scopeKey: $coords['scope'],
                    source: ParsingRuleSource::Import,
                );
                $this->info(sprintf('Опубликована и активирована версия v%d (id=%d).', $version->version, $version->id));
            }
        } catch (InvalidParsingRuleSetException $e) {
            $this->error('Ошибка валидации правил:');
            foreach ($e->result->issues as $issue) {
                $this->line(sprintf(' - [%s] %s: %s', $issue->severity, $issue->path, $issue->message));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
