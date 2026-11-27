<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate\Eval;

use RuntimeException;

/**
 * Внутренний накопитель JSONL-потока и счётчиков экспорта датасета.
 *
 * @internal
 */
final class ExportWriter
{
    /** @var resource */
    private $stream;

    private int $count = 0;

    /** @var array<string, int> */
    private array $skipped;

    /** @var array<string, int> */
    private array $bySource = [];

    /** @var array<string, int> */
    private array $bySplit = [];

    /** @var array<string, int> */
    private array $byRepresentationKind = [];

    /**
     * @param  array<string, int>  $initialSkipped
     */
    public function __construct(array $initialSkipped = [])
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Не удалось открыть временный поток для экспорта датасета.');
        }

        $this->stream = $stream;
        $this->skipped = $initialSkipped;
    }

    public function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function append(array $line, string $source, string $rowSplit, string $repKind): void
    {
        fwrite($this->stream, json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
        $this->count++;
        $this->bySource[$source] = ($this->bySource[$source] ?? 0) + 1;
        $this->bySplit[$rowSplit] = ($this->bySplit[$rowSplit] ?? 0) + 1;
        $this->byRepresentationKind[$repKind] = ($this->byRepresentationKind[$repKind] ?? 0) + 1;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * @param  array<string, int>  $promptVersionSet
     * @param  list<string>  $warnings
     * @return array{0: array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>}, 1: resource}
     */
    public function finish(array $promptVersionSet, string $rulesHash, array $warnings): array
    {
        rewind($this->stream);

        return [
            [
                'examples' => $this->count,
                'skipped' => array_filter($this->skipped),
                'by_source' => $this->bySource,
                'by_split' => $this->bySplit,
                'by_representation_kind' => $this->byRepresentationKind,
                'prompt_version_set' => $promptVersionSet,
                'rules_hash' => $rulesHash,
                'warnings' => $warnings,
            ],
            $this->stream,
        ];
    }
}
