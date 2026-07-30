<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Exceptions\Procurement\SplitPoolEmpty;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\FixtureStoreRepository;
use RuntimeException;

/**
 * Пересобирает `estimates/eval-splits.json` по правилу отбора D119 (детерминированное,
 * `research/18-holdout-split-rule.md` §6): пул — `gold_source=deterministic`, группировка по
 * `form` (возрастание), группа из одного эталона остаётся в `tune` целиком, в группе ≥2 берётся
 * ПЕРВЫЙ по возрастанию кода кандидат, чей уход в `holdout` не оставляет `tune` без единого
 * эталона его `source_format`. `tune` в файле — снимок дополнения пула на момент генерации
 * (D120), не определение фильтра. Вынесено из `EvalRunCommand::writeSplits()` (P3.3).
 */
final readonly class EvalSplitPlanner
{
    public function __construct(private FixtureStoreRepository $fixtures) {}

    /**
     * @return array{holdout: list<string>, tune: list<string>}
     *
     * @throws SplitPoolEmpty
     */
    public function plan(): array
    {
        $pool = $this->fixtures->deterministicGoldPool();

        if ($pool->isEmpty()) {
            throw new SplitPoolEmpty('Пул детерминированного gold пуст (prc_ai_fixtures.gold_source=deterministic) — нечего разбивать.');
        }

        $holdout = [];

        foreach ($pool->groupBy('form') as $group) {
            if ($group->count() < 2) {
                continue;
            }

            foreach ($group->sortBy('code') as $candidate) {
                $remaining = $pool->reject(
                    fn (Fixture $fixture): bool => in_array($fixture->code, $holdout, true) || $fixture->code === $candidate->code,
                );

                if ($remaining->contains(fn (Fixture $fixture): bool => $fixture->source_format === $candidate->source_format)) {
                    $holdout[] = $candidate->code;

                    break;
                }
            }
        }

        $tune = $pool->pluck('code')
            ->reject(fn (string $code): bool => in_array($code, $holdout, true))
            ->values()
            ->all();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'rule' => 'holdout: form-stratified (D119) — группы по form (возрастание), в группе ≥2 '
                .'первый по коду кандидат, чей уход не оставляет tune без эталона его source_format; '
                .'форма с единственным эталоном остаётся в tune целиком. tune — дополнение пула, не '
                .'определение фильтра (D120).',
            'holdout' => $holdout,
            'tune' => $tune,
        ];

        file_put_contents(
            (string) config('ai.fixtures.splits_path'),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        return ['holdout' => $holdout, 'tune' => $tune];
    }

    /**
     * @return list<string>
     */
    public function loadHoldoutCodes(): array
    {
        $path = (string) config('ai.fixtures.splits_path');

        if (! is_file($path)) {
            throw new RuntimeException("Файл разбиения не найден: {$path}. Прогони `estimate:eval:run --write-splits`.");
        }

        $decoded = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        return array_values((array) ($decoded['holdout'] ?? []));
    }
}
