<?php

declare(strict_types=1);

test('таймаут каждого horizon-супервизора строго меньше retry_after очереди redis (инвариант D26)', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    foreach ((array) config('horizon.defaults') as $name => $supervisor) {
        expect($supervisor['timeout'])->toBeLessThan(
            $retryAfter,
            "Супервизор \"{$name}\": timeout={$supervisor['timeout']}s ≥ retry_after={$retryAfter}s — ".
            'Redis сочтёт job осиротевшим и передиспатчит его другому воркеру, пока первый ещё легитимно работает (D26).',
        );
    }
});

test('checkpoint_seconds извлечения строго меньше timeout supervisor-ai-extraction (инвариант D31)', function () {
    $checkpointSeconds = (int) config('ai.extraction.checkpoint_seconds');
    $timeout = (int) config('horizon.defaults.supervisor-ai-extraction.timeout');

    expect($checkpointSeconds)->toBeLessThan(
        $timeout,
        "checkpoint_seconds={$checkpointSeconds}s ≥ supervisor-ai-extraction.timeout={$timeout}s — ".
        'self-requeue не успеет сработать раньше, чем Horizon убьёт job по таймауту (D31).',
    );
});

test('самый длинный horizon-супервизор задаёт нижнюю границу для документированного stop_grace_period (инвариант D26)', function () {
    $longestSupervisorTimeout = collect((array) config('horizon.defaults'))->max('timeout');

    // docker-compose.prod.yml не читается из PHP-рантайма — здесь фиксируется задокументированный
    // канон (research/J §2: 600–3600s), чтобы будущая правка config/horizon.php молча не пробила
    // потолок, зашитый комментарием в docker-compose.prod.yml.
    $documentedStopGracePeriodSeconds = 600;

    expect($longestSupervisorTimeout)->toBeLessThan($documentedStopGracePeriodSeconds);
});
