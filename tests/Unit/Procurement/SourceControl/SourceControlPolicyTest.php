<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\SourceControlPolicy;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceFailureReaction;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Exceptions\Procurement\Crawler\SourceControlConfigurationException;

/** @return array<string, mixed> */
function sourceControlConfig(): array
{
    $config = require base_path('config/crawler.php');

    return $config['source_control'];
}

test('конфиг проекта разбирается: статусы и реакции по умолчанию', function (): void {
    $policy = SourceControlPolicy::fromConfig(sourceControlConfig());

    expect($policy->kindForStatus(404))->toBe(SourceFailureKind::NotFound)
        ->and($policy->kindForStatus(503))->toBe(SourceFailureKind::SourceUnavailable)
        ->and($policy->kindForStatus(418))->toBe(SourceFailureKind::UnexpectedStatus)
        ->and($policy->stage(SourceStage::Listing)->reactionFor(SourceFailureKind::NotFound))->toBe(SourceFailureReaction::LogOnly)
        ->and($policy->stage(SourceStage::Detail)->reactionFor(SourceFailureKind::LayoutDrift))->toBe(SourceFailureReaction::CountTowardHold)
        ->and($policy->stage(SourceStage::Detail)->reactionFor(SourceFailureKind::CodeError))->toBe(SourceFailureReaction::Record);
});

test('ошибка конфига валит разбор с адресом ошибки', function (Closure $break, string $message): void {
    $config = sourceControlConfig();
    $break($config);

    expect(fn () => SourceControlPolicy::fromConfig($config))->toThrow(SourceControlConfigurationException::class, $message);
})->with([
    'не все виды' => [function (array &$c): void {
        unset($c['stages']['detail']['reactions'][SourceFailureKind::NotFound->value]);
    }, 'stages.detail.reactions: не задана реакция для «not_found»'],
    'опечатка в виде' => [function (array &$c): void {
        $c['stages']['listing']['reactions']['not_fund'] = SourceFailureReaction::LogOnly;
    }, 'stages.listing.reactions.not_fund: неизвестный вид отказа'],
    'строка вместо enum' => [function (array &$c): void {
        $c['stages']['listing']['reactions']['not_found'] = 'log_only';
    }, 'stages.listing.reactions.not_found: значение должно быть SourceFailureReaction'],
    'нет этапа' => [function (array &$c): void {
        unset($c['stages']['detail']);
    }, 'stages: нет этапа «detail»'],
    'лишний этап' => [function (array &$c): void {
        $c['stages']['documents'] = [];
    }, 'stages.documents: неизвестный этап'],
    'порог 0' => [function (array &$c): void {
        $c['stages']['listing']['layout_threshold'] = 0;
    }, 'stages.listing.layout_threshold: ожидалось целое ≥ 1'],
    'статус-строка' => [function (array &$c): void {
        $c['http_status_kinds'][404] = 'not_found';
    }, 'http_status_kinds.404: значение должно быть SourceFailureKind'],
    'не статус' => [function (array &$c): void {
        $c['http_status_kinds'][200] = SourceFailureKind::NotFound;
    }, 'http_status_kinds.200: ключ — HTTP-статус 300–599'],
]);
