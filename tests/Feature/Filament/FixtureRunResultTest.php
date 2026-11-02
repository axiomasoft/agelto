<?php

declare(strict_types=1);

test('generic-рендер сводки печатает заголовок, пары «подпись → значение» и факт обрезания', function (): void {
    $metrics = array_map(
        static fn (int $index): array => ['label' => "Метрика {$index}", 'value' => (string) $index, 'unit' => 'шт'],
        range(1, 20),
    );

    $html = view('filament.resources.fixtures.run-result', [
        'getState' => fn (): array => [
            'title' => 'Заголовок от анализатора',
            'metrics' => $metrics,
            'total' => 25,
            'hidden' => 5,
            'url' => null,
        ],
    ])->render();

    expect($html)->toContain('Заголовок от анализатора')
        ->toContain('Метрика 1')
        ->toContain('Показаны первые 20 строк из 25');
});

test('generic-рендер сводки показывает честное сообщение вместо пустого блока', function (): void {
    $html = view('filament.resources.fixtures.run-result', [
        'getState' => fn (): array => ['message' => 'Прогон ещё идёт — разбор появится после его завершения.'],
    ])->render();

    expect($html)->toContain('Прогон ещё идёт')
        ->not->toContain('<dl');
});
