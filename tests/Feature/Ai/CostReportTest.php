<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Services\Llm\LlmSpendReporter;
use Carbon\CarbonImmutable;
use Tests\Support\ProcessCore\ProviderReportFixtures;

beforeEach(fn () => ProviderReportFixtures::reset());

test('отчёт группирует расход по (provider, model, purpose) и считает итого за окно', function (): void {
    ProviderReportFixtures::store([
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'prompt_tokens' => 1_000,
        'cached_tokens' => 500,
        'completion_tokens' => 200,
        'cost_micros' => 12_345,
        'called_at' => CarbonImmutable::now()->subDay(),
    ]);
    ProviderReportFixtures::store([
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'prompt_tokens' => 500,
        'cached_tokens' => 0,
        'completion_tokens' => 100,
        'cost_micros' => 5_000,
        'called_at' => CarbonImmutable::now()->subDay(),
    ]);
    // За пределами окна --days=7 — не должен попасть в отчёт. Смещение НЕБОЛЬШОЕ (не через
    // границу месяца) — `llm_calls` партиционирована RANGE(called_at), премейк-партиции
    // (миграция 2026_07_04_100026) покрывают только текущий+2 месяца вперёд от даты миграции.
    ProviderReportFixtures::store([
        'provider' => 'gemini',
        'model' => 'gemini-2.5-pro',
        'purpose' => CoreLlmPurpose::Screening->value,
        'cost_micros' => 999_999,
        'called_at' => CarbonImmutable::now()->subDays(8),
    ]);

    // ВНИМАНИЕ: Laravel-мок `expectsOutputToContain()` матчит doWrite()-вызовы 1:1 с
    // зарегистрированными ожиданиями — НЕСКОЛЬКО ожиданий, совпадающих с ОДНОЙ и той же строкой
    // вывода (одна строка таблицы), «съедают» друг друга (только первое регистрируется как
    // найденное). Поэтому — по одному ожиданию на строку вывода, не два на одну.
    $this->artisan('ai:cost-report', ['--days' => 7])
        ->assertSuccessful()
        ->expectsOutputToContain('generate')
        // Итого = (12_345 + 5_000) / 1e6 = $0.0173 — ЕСЛИ бы вне-окна pro-вызов (cost_micros
        // 999_999) утёк в сумму, итог был бы совсем другим; так отсутствие утечки доказывается
        // числом, без хрупкого поиска подстроки «gemini-2.5-pro» в этой же выводимой строке.
        ->expectsOutputToContain('$0.0173');
});

test('пустое окно — сообщение без падения и без таблицы', function (): void {
    $this->artisan('ai:cost-report', ['--days' => 1])
        ->assertSuccessful()
        ->expectsOutputToContain('не найдено');
});

test('историческая экономия кэша остаётся unknown без pinned counterfactual pricing', function (): void {
    config(['ai.capabilities' => array_merge((array) config('ai.capabilities', []), [
        'gemini-2.5-flash' => [
            'input_per_1m' => 0.30,
            'output_per_1m' => 2.50,
            'cached_per_1m' => 0.03,
        ],
    ])]);

    ProviderReportFixtures::store([
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'cached_tokens' => 1_000_000,
        'cost_micros' => 300_000,
        'called_at' => CarbonImmutable::now(),
    ]);

    // экономия = 1_000_000 × (0.30 − 0.03) = 270_000 микро-долларов = $0.2700.
    $this->artisan('ai:cost-report', ['--days' => 1])
        ->assertSuccessful();
    expect(app(LlmSpendReporter::class)->report(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addMinute())->totalSavingsMicros)->toBeNull();
});

test('--days влияет на нижнюю границу окна', function (): void {
    ProviderReportFixtures::store([
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash-lite',
        'purpose' => CoreLlmPurpose::Screening->value,
        'cost_micros' => 1_000,
        'called_at' => CarbonImmutable::now()->subDays(3),
    ]);

    $this->artisan('ai:cost-report', ['--days' => 1])
        ->assertSuccessful()
        ->expectsOutputToContain('не найдено');

    $this->artisan('ai:cost-report', ['--days' => 5])
        ->assertSuccessful()
        ->expectsOutputToContain('gemini-2.5-flash-lite');
});
