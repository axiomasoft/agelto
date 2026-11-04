<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ControlChangeResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\ControlChangeStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Models\Procurement\Process\ControlScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writer control authority rows `prc_control_scopes`.
 * Scope существует до первой работы: default-enabled и manual override живут в одной row, поэтому
 * pause/admission всегда lock-ят существующую строку. ensureScopes — отдельная tx без admission:
 * существующие scopes запроса FOR SHARE в sorted scope_key, затем INSERT недостающих по unique key.
 * Parent/key immutable. setMode — manual pause/resume той же row; scope_key сортируется
 * byte order (COLLATE "C") во всех lockers.
 */
final readonly class ControlRepository
{
    private const int REASON_LIMIT = 200;

    public function __construct(private TransitionRepository $transitions) {}

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
        foreach (ControlScope::query()->whereIn('scope_key', $keys)->orderByRaw('scope_key COLLATE "C"')->sharedLock()->get() as $row) {
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
     * Unlocked pre-read desired mode существующих scopes; отсутствующий key не возвращается.
     *
     * @param  list<string>  $keys
     * @return array<string, ControlMode>
     */
    public function modes(array $keys): array
    {
        $modes = [];
        foreach (LedgerRow::all('SELECT scope_key, desired_mode FROM prc_control_scopes WHERE scope_key IN ('.LedgerRow::placeholders(count($keys)).')', $keys) as $row) {
            $modes[$row->string('scope_key')] = ControlMode::from($row->string('desired_mode'));
        }

        return $modes;
    }

    /**
     * Manual pause/resume одной scope: ancestors FOR SHARE и
     * изменяемая row FOR UPDATE в общем byte-sorted scope_key порядке, без SHARE→UPDATE upgrade.
     * Сериализуется с admission/claim, которые держат ту же closure FOR SHARE: после commit pause
     * новые grants запрещены. Resume снимает только manual mode этой row и увеличивает
     * control_version; health/handler holds и blocked work не трогает.
     */
    public function setMode(
        string $scopeKey,
        ControlMode $mode,
        ?int $changedBy,
        string $reason,
        int $expectedVersion,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): ControlChangeResult {
        CallerTransaction::require('Control mode change');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_LIMIT || str_contains($reason, "\0")) {
            throw new \InvalidArgumentException('Control change requires a short nonempty reason.');
        }
        // Topology immutable: unlocked чтение ancestor chain, затем locks в sorted порядке.
        $chain = LedgerRow::all(
            'WITH RECURSIVE chain AS (
               SELECT id, scope_key, parent_id FROM prc_control_scopes WHERE scope_key = ?
               UNION ALL
               SELECT parent.id, parent.scope_key, parent.parent_id FROM prc_control_scopes parent JOIN chain ON parent.id = chain.parent_id
             ) SELECT id, scope_key FROM chain ORDER BY scope_key COLLATE "C"',
            [$scopeKey],
        );
        $target = null;
        foreach ($chain as $scope) {
            $mine = $scope->string('scope_key') === $scopeKey;
            $row = LedgerRow::one(
                'SELECT id, desired_mode, control_version FROM prc_control_scopes WHERE id = ? '.($mine ? 'FOR UPDATE' : 'FOR SHARE'),
                [$scope->string('id')],
            ) ?? throw new \LogicException('Control scope disappeared: scope rows are never deleted.');
            $target = $mine ? $row : $target;
        }
        if ($target === null) {
            return new ControlChangeResult(ControlChangeStatus::NotFound, null);
        }
        $version = $target->int('control_version');
        if ($version !== $expectedVersion) {
            return new ControlChangeResult(ControlChangeStatus::VersionConflict, $version);
        }
        $previous = $target->string('desired_mode');
        if ($previous === $mode->value) {
            return new ControlChangeResult(ControlChangeStatus::Unchanged, $version);
        }
        $changed = LedgerRow::one(
            'UPDATE prc_control_scopes
             SET desired_mode = ?, control_version = control_version + 1, changed_by = ?, change_reason = ?, changed_at = clock_timestamp()
             WHERE id = ? AND control_version = ? RETURNING control_version',
            [$mode->value, $changedBy, $reason, $target->string('id'), $version],
        ) ?? throw new \LogicException('Control version fence did not match the locked scope.');
        $this->transitions->recordControl(
            $eventId, $projectionMessageId, new ProcessUuid($target->string('id')), $changed->int('control_version'),
            $previous, $mode->value, 'manual_'.$mode->value, $changedBy === null ? ActorKind::System : ActorKind::Operator, $changedBy,
            ['reason' => $reason],
        );

        return new ControlChangeResult(ControlChangeStatus::Applied, $changed->int('control_version'));
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
