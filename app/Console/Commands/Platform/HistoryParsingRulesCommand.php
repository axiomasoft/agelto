<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Commands\Platform\Concerns\InteractsWithParsingRules;
use App\Repositories\Procurement\Parsing\ParsingRuleReadRepository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'parsing-rules:history')]
#[Signature('parsing-rules:history {platform} {stage} {--registry=} {--scope=default}')]
#[Description('Display version history and lifecycle events for a parsing rule set')]
final class HistoryParsingRulesCommand extends Command
{
    use InteractsWithParsingRules;

    public function handle(ParsingRuleReadRepository $readRepository): int
    {
        $coords = $this->resolveTargetCoordinates();
        if ($coords === null) {
            return self::INVALID;
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

        $versions = $readRepository->listVersions($ruleSet->id);
        $events = $readRepository->listEvents($ruleSet->id, 25);

        $this->info(sprintf(
            'Набор #%d (%s / %s / scope=%s), active_version_id=%s, mode=%s',
            $ruleSet->id,
            $coords['platform']->code,
            $coords['stage']->value,
            $coords['scope'],
            $ruleSet->active_version_id !== null ? (string) $ruleSet->active_version_id : 'none',
            $ruleSet->auto_heal_mode->value,
        ));

        $versionRows = [];
        foreach ($versions as $version) {
            $versionRows[] = [
                'v'.$version->version,
                $version->id,
                $version->status->value,
                $version->source->value,
                $version->change_summary,
                $version->created_at->format('Y-m-d H:i:s'),
            ];
        }

        $this->table(['Версия', 'ID', 'Статус', 'Источник', 'Описание', 'Создана'], $versionRows);

        if ($events->isNotEmpty()) {
            $this->newLine();
            $this->info('Последние события:');
            $eventRows = [];
            foreach ($events as $event) {
                $eventRows[] = [
                    $event->occurred_at->format('Y-m-d H:i:s'),
                    $event->event_type->value,
                    $event->actor_type->value,
                    $event->rule_version_id !== null ? (string) $event->rule_version_id : '-',
                    $event->reason,
                ];
            }
            $this->table(['Время', 'Событие', 'Инициатор', 'Версия ID', 'Причина'], $eventRows);
        }

        return self::SUCCESS;
    }
}
