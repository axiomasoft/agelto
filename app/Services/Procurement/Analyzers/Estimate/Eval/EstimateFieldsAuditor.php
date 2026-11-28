<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate\Eval;

use App\Models\Procurement\Estimate\Estimate;

final readonly class EstimateFieldsAuditor
{
    private const array REQUIRED_FIELDS = [
        'source_position_number',
        'code',
        'name',
        'unit',
        'quantity',
    ];

    /**
     * @return array{
     *     source: string,
     *     generated_at: string,
     *     tree: array<string, array{items: int, fields: array<string, array{filled: int, completeness: float}>}>,
     * }
     */
    public function audit(string $source): array
    {
        $tree = $source === 'gold'
            ? $this->auditGold()
            : $this->auditDatabase();

        return [
            'source' => $source,
            'generated_at' => now()->toIso8601String(),
            'tree' => $this->formatTree($tree),
        ];
    }

    /**
     * @param  array<string, array{items: int, fields: array<string, array{filled: int, completeness: float}>}>  $tree
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public function tableRows(array $tree): array
    {
        $rows = [];

        foreach ($tree as $type => $branch) {
            foreach ($branch['fields'] as $field => $metrics) {
                $rows[] = [
                    $type,
                    $field,
                    "{$metrics['filled']} / {$branch['items']}",
                    number_format($metrics['completeness'] * 100, 2, '.', '').'%',
                ];
            }
        }

        return $rows;
    }

    /** @return array<string, array{items: int, fields: array<string, array{filled: int}>}> */
    private function auditGold(): array
    {
        $paths = glob($this->goldDirectory().'/*.gold.json') ?: [];
        sort($paths);
        $tree = [];

        foreach ($paths as $path) {
            $gold = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($gold)) {
                continue;
            }

            $estimates = $gold['estimates'] ?? [];

            if (! is_array($estimates)) {
                continue;
            }

            foreach ($estimates as $estimate) {
                if (! is_array($estimate)) {
                    continue;
                }

                $passport = $estimate['passport'] ?? [];

                if (! is_array($passport)) {
                    $passport = [];
                }

                /** @var array<string, mixed> $passport */
                $type = $this->typeOf($passport);

                $rows = $estimate['rows'] ?? [];

                if (! is_array($rows)) {
                    continue;
                }

                foreach ($rows as $row) {
                    if (! is_array($row) || ($row['row_type'] ?? null) !== 'item') {
                        continue;
                    }

                    /** @var array<string, mixed> $row */
                    $this->addItem($tree, $type, $row);
                }
            }
        }

        return $tree;
    }

    /** @return array<string, array{items: int, fields: array<string, array{filled: int}>}> */
    private function auditDatabase(): array
    {
        $tree = [];

        Estimate::query()
            ->select(['id', 'passport'])
            ->with(['items:id,estimate_id,source_position_number,code,name,unit,quantity'])
            ->lazy()
            ->each(function (Estimate $estimate) use (&$tree): void {
                $passport = $estimate->passport?->all();

                if (! is_array($passport)) {
                    $passport = [];
                }

                /** @var array<string, mixed> $passport */
                $type = $this->typeOf($passport);

                foreach ($estimate->items as $item) {
                    $this->addItem($tree, $type, $item->only(self::REQUIRED_FIELDS));
                }
            });

        return $tree;
    }

    /**
     * @param  array<string, array{items: int, fields: array<string, array{filled: int}>}>  $tree
     * @param  array<string, mixed>  $item
     */
    private function addItem(array &$tree, string $type, array $item): void
    {
        $tree[$type] ??= [
            'items' => 0,
            'fields' => array_fill_keys(self::REQUIRED_FIELDS, ['filled' => 0]),
        ];
        $tree[$type]['items']++;

        foreach (self::REQUIRED_FIELDS as $field) {
            if ($this->hasValue($item[$field] ?? null)) {
                $tree[$type]['fields'][$field]['filled']++;
            }
        }
    }

    /**
     * @param  array<string, array{items: int, fields: array<string, array{filled: int}>}>  $tree
     * @return array<string, array{items: int, fields: array<string, array{filled: int, completeness: float}>}>
     */
    private function formatTree(array $tree): array
    {
        ksort($tree);
        $formatted = [];

        foreach ($tree as $type => $branch) {
            $fields = [];
            foreach ($branch['fields'] as $name => $field) {
                $fields[$name] = [
                    'filled' => $field['filled'],
                    'completeness' => $branch['items'] === 0
                        ? 0.0
                        : round($field['filled'] / $branch['items'], precision: 6),
                ];
            }
            $formatted[$type] = ['items' => $branch['items'], 'fields' => $fields];
        }

        return $formatted;
    }

    /** @param array<string, mixed> $passport */
    private function typeOf(array $passport): string
    {
        $type = $passport['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : 'unspecified';
    }

    private function goldDirectory(): string
    {
        $goldDirectory = config('ai.fixtures.gold_dir');

        if (! is_string($goldDirectory)) {
            throw new \UnexpectedValueException('ai.fixtures.gold_dir must be a string.');
        }

        return $goldDirectory;
    }

    private function hasValue(mixed $value): bool
    {
        if (! is_string($value)) {
            return $value !== null;
        }

        return trim($value) !== '';
    }
}
