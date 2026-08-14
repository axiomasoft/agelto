<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Models\Procurement\Estimate\Estimate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

/** Read-only audit of the five mandatory estimate-item fields over gold files or persisted estimates. */
#[Description('Измерить заполненность обязательных полей позиций сметы')]
#[Signature('estimate:fields:audit
        {--source=gold : Источник: gold или database}
        {--json : Машинный JSON вместо таблицы}')]
final class FieldsAuditCommand extends Command
{
    private const array REQUIRED_FIELDS = [
        'source_position_number',
        'code',
        'name',
        'unit',
        'quantity',
    ];

    public function handle(): int
    {
        $source = $this->option('source');

        if (! is_string($source) || ! in_array($source, ['gold', 'database'], strict: true)) {
            $this->error('Источник должен быть gold или database.');

            return self::FAILURE;
        }

        try {
            $tree = $source === 'gold'
                ? $this->auditGold()
                : $this->auditDatabase();
        } catch (JsonException $exception) {
            $this->error("Не удалось прочитать gold-корпус: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $audit = [
            'source' => $source,
            'generated_at' => now()->toIso8601String(),
            'tree' => $this->formatTree($tree),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderTable($audit['tree']);

        return self::SUCCESS;
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

        foreach ($tree as &$branch) {
            foreach ($branch['fields'] as &$field) {
                $field['completeness'] = $branch['items'] === 0
                    ? 0.0
                    : round($field['filled'] / $branch['items'], 6);
            }
        }

        return $tree;
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

    /** @param array<string, array{items: int, fields: array<string, array{filled: int, completeness: float}>}> $tree */
    private function renderTable(array $tree): void
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

        $this->table(['Тип сметы', 'Поле', 'Заполнено', 'Доля'], $rows);
    }

    private function hasValue(mixed $value): bool
    {
        if (! is_string($value)) {
            return $value !== null;
        }

        return trim($value) !== '';
    }
}
