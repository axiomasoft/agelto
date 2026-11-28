<?php

declare(strict_types=1);

use App\Services\Procurement\Network\HealthPolicy;
use App\Services\Procurement\Network\OutboundPolicy;
use Illuminate\Config\Repository;

/** Historical scenario identities; canonical policy replaces retired crawler.source_control. */
function sourceControlConfig(): array
{
    return require base_path('config/outbound.php');
}

test('конфиг проекта разбирается: статусы и реакции по умолчанию', function (): void {
    $config = new Repository(['outbound' => sourceControlConfig()]);
    $policy = new OutboundPolicy($config);
    $health = new HealthPolicy($config);
    expect($policy->originBudget->ratePerMinute)->toBe(15)
        ->and($policy->originBudget->burst)->toBe(1)
        ->and($policy->defaultRouteProfile)->toBe('default')
        ->and($policy->providerRouteProfile)->toBe('llm')
        ->and($health->globalFailures)->toBe(5)->and($health->targetFailures)->toBe(5)
        ->and($health->globalBase)->toBe(1800)->and($health->targetBase)->toBe(300);
});

test('ошибка конфига валит разбор с адресом ошибки', function (Closure $break, string $message): void {
    $config = sourceControlConfig();
    $break($config);
    expect(fn () => new OutboundPolicy(new Repository(['outbound' => $config])))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'не все виды' => [function (array &$c): void {
        unset($c['health']['global_consecutive']);
    }, 'outbound.health'],
    'опечатка в виде' => [function (array &$c): void {
        $c['health']['global_consecutiv'] = 5;
    }, 'outbound.health'],
    'строка вместо enum' => [function (array &$c): void {
        $c['health']['global_consecutive'] = '5';
    }, 'outbound.health.global_consecutive'],
    'нет этапа' => [function (array &$c): void {
        unset($c['routing']['provider_profile']);
    }, 'outbound.routing'],
    'лишний этап' => [function (array &$c): void {
        $c['routing']['unknown_profile'] = 'x';
    }, 'outbound.routing'],
    'порог 0' => [function (array &$c): void {
        $c['health']['route_target_consecutive'] = 0;
    }, 'outbound.health.route_target_consecutive'],
    'статус-строка' => [function (array &$c): void {
        $c['scheduling']['status_capacity_percent'] = '30';
    }, 'outbound.scheduling.status_capacity_percent'],
    'не статус' => [function (array &$c): void {
        $c['scheduling']['status_capacity_percent'] = 101;
    }, 'outbound.scheduling.status_capacity_percent'],
]);
