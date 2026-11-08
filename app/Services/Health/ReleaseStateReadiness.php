<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Models\Llm\LlmRoute;
use App\Models\Llm\Prompt;
use App\Models\Llm\Provider;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Models\User;
use App\Services\Llm\PromptCanonSynchronizer;

final readonly class ReleaseStateReadiness
{
    public function __construct(private PromptCanonSynchronizer $promptCanon) {}

    public function baseDataIsReady(): bool
    {
        $eis = Platform::query()->where('code', 'eis')->first();
        $registry = $eis instanceof Platform
            ? Registry::query()->where('platform_id', $eis->id)->where('code', 'fz44')->first()
            : null;
        $provider = Provider::query()->where('code', 'gemini')->first();

        return Platform::query()->where('code', Platform::TRAINING_CODE)->exists()
            && $eis instanceof Platform
            && $registry instanceof Registry
            && Okpd2Node::query()->where('platform_id', $eis->id)->exists()
            && Crawler::query()->where('registry_id', $registry->id)->whereIn('name', [
                'ЕИС 44-ФЗ — архив 2025 (завершённые)',
                'ЕИС 44-ФЗ — архив 2026 (завершённые)',
                'ЕИС 44-ФЗ — свежий поток',
            ])->count() === 3
            && StatusMapping::query()->where('registry_id', $registry->id)->count() === 5
            && $provider instanceof Provider
            && LlmRoute::query()->where('provider_id', $provider->id)->where('shadow', false)->count() === 11
            && Prompt::query()->where('is_active', true)->where('is_protected', true)->exists()
            && $this->promptCanon->drift() === [];
    }

    public function initialAdminIsReady(): bool
    {
        $email = config(key: 'cms.admin_email');

        if (! is_string($email) || trim($email) === '') {
            return false;
        }

        return User::query()
            ->where('email', mb_strtolower(trim($email)))
            ->get()
            ->contains(fn (User $user): bool => $user->isSuperAdmin());
    }
}
