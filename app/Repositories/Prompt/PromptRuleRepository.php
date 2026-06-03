<?php

declare(strict_types=1);

namespace App\Repositories\Prompt;

use App\Dto\Prompt\Step;
use App\Models\Prompt\PromptRule;
use App\Services\PromptService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PromptRuleRepository
{
    public function __construct(
        private readonly PromptService $promtService,
    ) {}

    /**
     * Сначала правила из БД, в конце — содержимое файлов схем для каждого процесса (Responses в конце).
     */
    public function getSystemInstructionsForStep(Step $step): string
    {
        $steps = $this->stepsForInstruction(step: $step);

        $parts = [];

        $placeholders = implode(', ', array_fill(0, count($steps), '?'));
        $dbContent = PromptRule::query()
            ->whereIn('step', $steps)
            ->where('is_active', true)
            ->orderByRaw("FIELD(step, {$placeholders})", $steps)
            ->orderBy('priority')
            ->pluck('content')
            ->implode("\n\n---\n\n");

        if ($dbContent !== '') {
            $parts[] = $dbContent;
        }

        $schemaContent = $this->buildSchemaSourcesForSteps(steps: $steps);
        if ($schemaContent !== '') {
            $parts[] = $schemaContent;
        }

        return implode("\n\n---\n\n", $parts);
    }

    private static function processBasePath(): string
    {
        return app_path('Process');
    }

    /**
     * Собирает блок с содержимым файлов Schema для каждого процесса из steps (префикс до ":").
     * Файлы из папки Responses — в конце.
     */
    private function buildSchemaSourcesForSteps(array $steps): string
    {
        $processPrefixes = [];
        foreach ($steps as $stepValue) {
            if (str_contains($stepValue, ':')) {
                $processPrefixes[] = explode(':', $stepValue, 2)[0];
            }
        }
        $processPrefixes = array_unique($processPrefixes);

        $blocks = [];
        foreach ($processPrefixes as $prefix) {
            $folderName = implode('', array_map('ucfirst', explode('_', $prefix)));
            $schemaDir = self::processBasePath().'/'.$folderName.'/Schema';
            if (! is_dir($schemaDir)) {
                continue;
            }
            $files = $this->listSchemaPhpFiles(schemaDir: $schemaDir);
            foreach ($files as $filePath) {
                $blocks[] = '// --- Source: '.basename($filePath)." ---\n".$this->promtService->getCleanSchema($filePath);
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @return list<string> пути к .php файлам, файлы из Responses — в конце
     */
    private function listSchemaPhpFiles(string $schemaDir): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($schemaDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        usort($files, function (string $a, string $b): int {
            $aInResponses = str_contains($a, DIRECTORY_SEPARATOR.'Responses'.DIRECTORY_SEPARATOR);
            $bInResponses = str_contains($b, DIRECTORY_SEPARATOR.'Responses'.DIRECTORY_SEPARATOR);
            if ($aInResponses !== $bInResponses) {
                return $aInResponses ? 1 : -1;
            }

            return strcmp($a, $b);
        });

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function stepsForInstruction(Step $step): array
    {
        $base = [Step::Global->value];

        return match ($step) {
            Step::PrimaryAnalysis => [...$base, Step::GlobalPrimary->value, $step->value],
            Step::EstimatePreview, Step::EstimateFetchItems, Step::EstimateFetchInfo => [...$base, Step::GlobalEstimate->value, $step->value],
            default => [...$base, $step->value],
        };
    }
}
