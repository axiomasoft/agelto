<?php

declare(strict_types=1);

namespace App\Doctor\Diagnostics;

use App\Models\Llm\LlmRoute;
use App\Models\Llm\Prompt;
use App\Models\Llm\Provider;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Services\Llm\PromptCanonSynchronizer;
use Laravel\Doctor\Diagnostic;
use Laravel\Doctor\Results\DiagnosticResult;

final class BaseDataIsReady extends Diagnostic
{
    public string $name = 'Base data is ready';

    public string $group = 'agelto-release';

    public function check(): DiagnosticResult
    {
        $eis = Platform::query()->where('code', 'eis')->first();
        $registry = $eis instanceof Platform
            ? Registry::query()->where('platform_id', $eis->id)->where('code', 'fz44')->first()
            : null;
        $provider = Provider::query()->where('code', 'gemini')->first();

        $ready = Platform::query()->where('code', Platform::TRAINING_CODE)->exists()
            && $eis instanceof Platform
            && $registry instanceof Registry
            && Okpd2Node::query()->where('platform_id', $eis->id)->exists()
            && Crawler::query()->where('registry_id', $registry->id)->whereIn('name', [
                'ЕИС 44-ФЗ — архив 2025 (завершённые)',
                'ЕИС 44-ФЗ — свежий поток',
            ])->count() === 2
            && StatusMapping::query()->where('registry_id', $registry->id)->count() === 5
            && $provider instanceof Provider
            && LlmRoute::query()->where('provider_id', $provider->id)->where('shadow', false)->count() === 11
            && Prompt::query()->where('is_active', true)->where('is_protected', true)->exists()
            && app(PromptCanonSynchronizer::class)->drift() === [];

        return $ready ? $this->pass('ready') : $this->fail('missing');
    }

    protected function messages(): array
    {
        return [
            'ready' => 'All BaseSeeder graph nodes and the prompt canon are present.',
            'missing' => 'Base data is incomplete or drifted. Run make prod:seed-base after migrations.',
        ];
    }
}
