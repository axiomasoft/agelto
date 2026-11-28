<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages;

use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Exceptions\Procurement\Parsing\InvalidParsingRuleSetException;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\ParsingRuleSetResource;
use App\Guards\Admin\Permissions\ParsingRuleSetPermission;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use JsonException;

class CreateParsingRuleSet extends CreateRecord
{
    protected static string $resource = ParsingRuleSetResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $rawJson = is_string($data['rules_json'] ?? null) ? $data['rules_json'] : '';

        try {
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ValidationException::withMessages([
                'rules_json' => 'Некорректный JSON: '.$e->getMessage(),
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'rules_json' => 'Правила должны быть JSON-объектом.',
            ]);
        }

        /** @var array<string, mixed> $decoded */
        $userId = Auth::id();
        $actorId = is_int($userId) ? $userId : null;
        $registryCode = isset($data['registry_code']) && is_string($data['registry_code']) && trim($data['registry_code']) !== ''
            ? trim($data['registry_code'])
            : null;
        $platformId = is_numeric($data['platform_id'] ?? null) ? (int) $data['platform_id'] : 0;
        $stageRaw = is_string($data['stage'] ?? null) ? $data['stage'] : '';
        if (! in_array($stageRaw, [StageCode::Listing->value, StageCode::Detail->value, StageCode::StatusRefresh->value], true)) {
            throw ValidationException::withMessages([
                'stage' => 'Недопустимая стадия для правил парсинга.',
            ]);
        }

        $changeSummary = is_string($data['change_summary'] ?? null) ? $data['change_summary'] : 'Черновик';
        $scopeKey = is_string($data['scope_key'] ?? null) ? $data['scope_key'] : 'default';
        $canConfigureAutoHeal = (Auth::user()?->hasPermission(ParsingRuleSetPermission::AutoHeal) ?? false)
            || (Auth::user()?->hasPermission(ParsingRuleSetPermission::Update) ?? false);
        $autoHealRaw = is_string($data['auto_heal_mode'] ?? null) ? $data['auto_heal_mode'] : AutoHealMode::Propose->value;
        $autoHealMode = $canConfigureAutoHeal
            ? (AutoHealMode::tryFrom($autoHealRaw) ?? AutoHealMode::Propose)
            : AutoHealMode::Propose;

        try {
            $version = app(ParsingRuleManager::class)->createDraft(
                platformId: $platformId,
                stage: StageCode::from($stageRaw),
                rules: $decoded,
                changeSummary: $changeSummary,
                registryCode: $registryCode,
                scopeKey: $scopeKey,
                source: ParsingRuleSource::Manual,
                createdBy: $actorId,
                actorType: ParsingRuleActorType::User,
                actorId: $actorId,
                autoHealMode: $autoHealMode,
            );
        } catch (InvalidParsingRuleSetException $e) {
            throw ValidationException::withMessages([
                'rules_json' => $e->getMessage(),
            ]);
        }

        return $version->ruleSet;
    }
}
