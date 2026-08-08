<?php

declare(strict_types=1);

use App\Services\Procurement\History\RevisionDiffService;

test('diff возвращает только изменённые ключи со словарной подписью', function (): void {
    $diff = app(RevisionDiffService::class)->diff(
        oldSnapshot: ['title' => 'Старое', 'currency' => 'RUB'],
        newSnapshot: ['title' => 'Новое', 'currency' => 'RUB'],
    );

    expect($diff->changes)->toBe([
        ['key' => 'title', 'label' => 'Наименование', 'old' => 'Старое', 'new' => 'Новое'],
    ]);
});

test('diff показывает добавленный и удалённый ключи без эвристик', function (): void {
    $diff = app(RevisionDiffService::class)->diff(
        oldSnapshot: ['currency' => 'RUB'],
        newSnapshot: ['initial_price' => '100.00'],
    );

    expect($diff->changes)->toBe([
        ['key' => 'currency', 'label' => 'Валюта', 'old' => 'RUB', 'new' => null],
        ['key' => 'initial_price', 'label' => 'Начальная цена', 'old' => null, 'new' => '100.00'],
    ]);
});

test('diff пуст для идентичных снапшотов', function (): void {
    $diff = app(RevisionDiffService::class)->diff(
        oldSnapshot: ['title' => 'Без изменений'],
        newSnapshot: ['title' => 'Без изменений'],
    );

    expect($diff->changes)->toBe([]);
});
