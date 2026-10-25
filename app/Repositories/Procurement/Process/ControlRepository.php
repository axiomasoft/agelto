<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ScopeDefinition;
use App\Models\Procurement\Process\ControlScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writer control authority rows `prc_control_scopes` (execution#s11, bp-lock-modes ensureScopes).
 * Scope существует до первой работы: default-enabled и manual override живут в одной row, поэтому
 * pause/admission всегда lock-ят существующую строку. ensureScopes — отдельная tx без admission:
 * существующие scopes запроса FOR SHARE в sorted scope_key, затем INSERT недостающих по unique key.
 * Parent/key immutable. Mode/version mutations (pause/resume) добавляют следующие пункты.
 */
final readonly class ControlRepository
{
    /**
     * Идемпотентно материализует полные цепочки scopes; каждый parent обязан входить в запрос.
     *
     * @param  list<ScopeDefinition>  $definitions
     */
    public function ensureScopes(array $definitions): void
    {
        CallerTransaction::require('Control scope ensure');
        $ordered = self::parentFirst($definitions);
        $keys = array_map(static fn (ScopeDefinition $definition): string => $definition->key, array_values($ordered));
        sort($keys, SORT_STRING);

        /** @var array<string, ControlScope> $rows */
        $rows = [];
        foreach (ControlScope::query()->whereIn('scope_key', $keys)->orderBy('scope_key')->sharedLock()->get() as $row) {
            $rows[$row->scope_key] = $row;
        }

        foreach ($ordered as $definition) {
            $key = $definition->key;
            $expectedParent = $definition->parentKey === null ? null : $rows[$definition->parentKey]->id;
            if (! isset($rows[$key])) {
                // Конкурентный creator того же key: ON CONFLICT ждёт его commit, затем читаем его row.
                DB::insert(
                    'INSERT INTO prc_control_scopes (id, scope_kind, scope_key, parent_id) VALUES (?, ?, ?, ?)
                     ON CONFLICT (scope_key) DO NOTHING',
                    [Str::lower((string) Str::uuid()), $definition->kind->value, $key, $expectedParent],
                );
                $rows[$key] = ControlScope::query()->where('scope_key', $key)->sharedLock()->sole();
            }
            if ($rows[$key]->scope_kind !== $definition->kind || $rows[$key]->parent_id !== $expectedParent) {
                throw new \LogicException('Control scope topology is immutable: '.$key);
            }
        }
    }

    /**
     * @param  list<ScopeDefinition>  $definitions
     * @return array<string, ScopeDefinition>
     */
    private static function parentFirst(array $definitions): array
    {
        $byKey = [];
        foreach ($definitions as $definition) {
            if (isset($byKey[$definition->key]) && $byKey[$definition->key] != $definition) {
                throw new \InvalidArgumentException('Conflicting definitions of control scope: '.$definition->key);
            }
            $byKey[$definition->key] = $definition;
        }
        if ($byKey === []) {
            throw new \InvalidArgumentException('At least one control scope is required.');
        }

        // Глобальный порядок (depth, key): конкурентные ensureScopes вставляют пересекающиеся keys
        // в одной последовательности и не ждут uncommitted unique keys друг друга по кругу.
        $depths = [];
        foreach ($byKey as $key => $definition) {
            $depth = 0;
            for ($parent = $definition->parentKey; $parent !== null; $parent = $byKey[$parent]->parentKey) {
                if (! isset($byKey[$parent]) || ++$depth > count($byKey)) {
                    throw new \InvalidArgumentException('Control scope chain must include every ancestor and no cycle: '.$key);
                }
            }
            $depths[$key] = $depth;
        }
        uksort($byKey, static fn (int|string $a, int|string $b): int => [$depths[$a], (string) $a] <=> [$depths[$b], (string) $b]);

        return $byKey;
    }
}
