<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ContentHash;
use App\Services\Procurement\Process\Contracts\ProjectionReducer;

/**
 * Versioned registry зарегистрированных projectors. Boot-validated:
 * уникальные projector_key, каждая серия каталога принадлежит ровно одному reducer, kinds
 * принадлежат ledger. Отсутствие reducer для kind события — ошибка конфигурации, а не молчаливый
 * green skip: `forEntityKind` всегда возвращает хотя бы одного получателя для известного kind.
 */
final readonly class ProjectionRegistry
{
    /** @var list<string> entity_kind из CHECK prc_process_transitions */
    private const array KINDS = ['work', 'run', 'control', 'incident'];

    /** @var array<string, ProjectionReducer> */
    private array $reducers;

    /** @param list<ProjectionReducer>|null $reducers null — стандартные work/run/governance reducers */
    public function __construct(?array $reducers = null)
    {
        $reducers ??= [new WorkMetricReducer, new RunMetricReducer, new GovernanceMetricReducer];
        $byKey = [];
        $owners = [];
        $covered = [];
        foreach ($reducers as $reducer) {
            if (isset($byKey[$reducer->key()]) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reducer->key()) !== 1) {
                throw new \InvalidArgumentException('Invalid projection registry: projector key '.$reducer->key());
            }
            foreach ($reducer->metrics() as $metric) {
                if (! in_array($metric, MetricCatalog::metrics(), true) || isset($owners[$metric])) {
                    throw new \InvalidArgumentException('Invalid projection registry: metric ownership '.$metric);
                }
                $owners[$metric] = $reducer->key();
            }
            foreach ($reducer->entityKinds() as $kind) {
                if (! in_array($kind, self::KINDS, true)) {
                    throw new \InvalidArgumentException('Invalid projection registry: entity kind '.$kind);
                }
                $covered[$kind] = true;
            }
            $byKey[$reducer->key()] = $reducer;
        }
        if (array_diff(self::KINDS, array_keys($covered)) !== [] || array_diff(MetricCatalog::metrics(), array_keys($owners)) !== []) {
            throw new \InvalidArgumentException('Invalid projection registry: every kind needs a reducer and every metric an owner.');
        }
        $this->reducers = $byKey;
    }

    /** @return array<string, ProjectionReducer> */
    public function all(): array
    {
        return $this->reducers;
    }

    public function get(string $key): ProjectionReducer
    {
        return $this->reducers[$key] ?? throw new \InvalidArgumentException('Unknown projector: '.$key);
    }

    /** @return list<ProjectionReducer> получатели события этого kind, в порядке projector_key */
    public function forEntityKind(string $kind): array
    {
        $matching = array_filter($this->reducers, static fn (ProjectionReducer $reducer): bool => in_array($kind, $reducer->entityKinds(), true));
        ksort($matching);

        return array_values($matching);
    }

    /** Hash версии reducer и его серий: расхождение со state row запрещает live apply и rebuild. */
    public function catalogHash(ProjectionReducer $reducer): ContentHash
    {
        $metrics = $reducer->metrics();
        sort($metrics);
        $kinds = $reducer->entityKinds();
        sort($kinds);

        return ContentHash::of(implode("\n", [
            'projection-catalog:v1', $reducer->key(), (string) $reducer->version(), implode(',', $kinds),
            ...array_map(MetricCatalog::signature(...), $metrics),
        ]));
    }
}
