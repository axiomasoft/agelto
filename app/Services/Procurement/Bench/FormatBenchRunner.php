<?php

declare(strict_types=1);

namespace App\Services\Procurement\Bench;

use App\Dto\Procurement\Bench\BenchCellResult;
use App\Exceptions\Llm\UnsupportedConsumer;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\DocumentRepresentation;

/** Read-only planning matrix. Live benchmarking requires its own durable paid-operation owner. */
final readonly class FormatBenchRunner
{
    public const array VARIANTS = ['default', 'text', 'text_grid', 'structured_xml', 'pdf_alt'];

    /**
     * @param  list<string>  $onlyCodes
     * @param  list<string>  $onlyVariants
     * @return array{mode:string,generated_at:string,route:array{provider:string,model:string},prompt_version_set:array<string,int>,cells:list<array<string,mixed>>,rejected:list<string>}
     */
    public function run(array $onlyCodes, array $onlyVariants, bool $dryRun, ?int $limit): array
    {
        if (! $dryRun) {
            throw new UnsupportedConsumer;
        }
        $query = DatasetExample::query()->where('task', 'extraction')->with(['fixture', 'documentVersion']);
        if ($onlyCodes !== []) {
            $query->whereHas('fixture', fn ($q) => $q->whereIn('code', $onlyCodes));
        }
        if ($limit !== null) {
            $query->limit(max(0, $limit));
        }
        $variants = $onlyVariants === [] ? self::VARIANTS : $onlyVariants;
        $cells = [];
        $rejected = [];
        foreach ($query->orderBy('id')->get() as $example) {
            if ($example->fixture === null || $example->documentVersion === null) {
                $rejected[] = (string) $example->id.': fixture/document_version missing';

                continue;
            }
            $fixture = $example->fixture;
            $version = $example->documentVersion;
            if (($fixture->type_profile['gold_source'] ?? null) !== 'deterministic') {
                continue;
            }
            if ($fixture->document_id !== $version->document_id) {
                $rejected[] = $fixture->code.': document is not the fixture working copy';

                continue;
            }
            foreach ($variants as $variant) {
                $representation = DocumentRepresentation::query()->where('document_version_id', $version->id)->where('kind', $variant)->orderByDesc('id')->first();
                if ($representation === null) {
                    $cells[] = BenchCellResult::notApplicable($fixture->code, $variant)->toArray();

                    continue;
                }
                $tool = $representation->getAttribute('tool_version');
                if (! is_string($tool)) {
                    throw new \LogicException('Stored representation tool version is missing.');
                }
                $cells[] = BenchCellResult::dry($fixture->code, $variant, [
                    'kind' => $variant, 'tool' => $tool, 'size_bytes' => $representation->size_bytes,
                    'page_count' => $representation->page_count, 'token_estimate' => $representation->token_estimate],
                    ['route' => null, 'reason' => 'durable_owner_required'],
                    ['input_tokens' => $representation->token_estimate, 'input_cost_micros' => null], [])->toArray();
            }
        }

        return ['mode' => 'dry', 'generated_at' => now()->toIso8601String(), 'route' => ['provider' => 'unselected', 'model' => 'unselected'],
            'prompt_version_set' => [], 'cells' => $cells, 'rejected' => $rejected];
    }
}
