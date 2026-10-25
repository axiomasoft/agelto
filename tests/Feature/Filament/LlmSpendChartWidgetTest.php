<?php

declare(strict_types=1);

use App\Filament\Widgets\LlmSpendChartWidget;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Llm\LlmCall;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// Смещения во времени НЕБОЛЬШИЕ (часы/сутки): `llm_calls` партиционирована RANGE(called_at),
// далёкая дата ушла бы в несуществующую партицию — тот же приём, что в `UsageSourceTest`.

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(fn () => artisan('guard:sync-roles'));

function llmSpendChartWidgetData(LlmSpendChartWidget $widget): array
{
    return (new ReflectionMethod($widget, 'getData'))->invoke($widget);
}

test('виджет расхода зарегистрирован в составе admin-панели', function (): void {
    expect(Filament::getPanel('admin')->getWidgets())->toContain(LlmSpendChartWidget::class);
});

test('дневной разрез: два вызова в одни сутки — одна точка оси X', function (): void {
    $moment = CarbonImmutable::now('UTC')->startOfDay()->addHours(12);
    $this->travelTo($moment);

    try {
        LlmCall::factory()->create(['cost_micros' => 1_000, 'called_at' => $moment]);
        LlmCall::factory()->create(['cost_micros' => 500, 'called_at' => $moment->subHours(2)]);

        $widget = new LlmSpendChartWidget;
        $widget->filter = '7';

        expect(llmSpendChartWidgetData($widget)['labels'])->toBe([$moment->toDateString()]);
    } finally {
        $this->travelBack();
    }
});

test('дневной разрез: два вызова в разные сутки — две точки оси X', function (): void {
    $today = CarbonImmutable::now();
    $yesterday = $today->subDay();

    LlmCall::factory()->create(['cost_micros' => 1_000, 'called_at' => $yesterday]);
    LlmCall::factory()->create(['cost_micros' => 2_000, 'called_at' => $today]);

    $widget = new LlmSpendChartWidget;
    $widget->filter = '7';

    expect(llmSpendChartWidgetData($widget)['labels'])->toBe([$yesterday->toDateString(), $today->toDateString()]);
});

test('серия провайдера без данных за чужой день получает 0, а не падает', function (): void {
    // Регресс-гвард: разные провайдеры редко пишут в одни и те же сутки — series-карта
    // одного провайдера легально не содержит день, заполненный ТОЛЬКО другим (черновик
    // считал это через nullsafe-доступ, PHPStan счёл его недостижимым — реальный риск
    // "Attempt to read property on null" на проде, разобрано построчной ревизией P4.3).
    $today = CarbonImmutable::now();
    $yesterday = $today->subDay();

    LlmCall::factory()->create(['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'cost_micros' => 1_000, 'called_at' => $yesterday]);
    LlmCall::factory()->create(['provider' => 'openai', 'model' => 'gpt-5', 'cost_micros' => 2_000, 'called_at' => $today]);

    $widget = new LlmSpendChartWidget;
    $widget->filter = '7';

    $data = llmSpendChartWidgetData($widget);

    expect($data['labels'])->toBe([$yesterday->toDateString(), $today->toDateString()])
        ->and($data['datasets'])->toBe([
            ['label' => 'gemini · gemini-2.5-flash', 'data' => [0.001, 0.0]],
            ['label' => 'openai · gpt-5', 'data' => [0.0, 0.002]],
        ]);
});

test('дневной разрез: пустая БД — пустые серии', function (): void {
    $widget = new LlmSpendChartWidget;
    $widget->filter = '7';

    $data = llmSpendChartWidgetData($widget);

    expect($data['labels'])->toBe([])
        ->and($data['datasets'])->toBe([]);
});

test('подпись источника — обязательный элемент виджета', function (): void {
    LlmCall::factory()->create(['called_at' => CarbonImmutable::now()]);

    $widget = new LlmSpendChartWidget;
    $widget->filter = '7';

    expect($widget->getDescription())->toContain('Источник: наш трекинг (llm_calls)');
});

test('подпись явно называет период за горизонтом retention, а не молчит пустой диаграммой', function (): void {
    config(['partitioning.retention.llm_calls.months' => 1]);

    LlmCall::factory()->create(['called_at' => CarbonImmutable::now()]);

    $widget = new LlmSpendChartWidget;
    $widget->filter = '90';

    expect($widget->getDescription())->toContain('партиции старше retention удалены');
});

test('право на виджет: суперадмин видит, пользователь без ролей — нет', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(SuperAdminRole::class);
    $guest = User::factory()->create();

    actingAs($superAdmin);
    $superAdminCanView = LlmSpendChartWidget::canView();

    actingAs($guest);
    $guestCanView = LlmSpendChartWidget::canView();

    // Одинаковый исход у обоих пользователей означает, что сработала одна из веток
    // degrade-to-allow `PageWidgetAccessEvaluator` (без панели/плагина/пользователя) —
    // тест обязан различить исходы, иначе право не проверяется вовсе (D89 исход «б»).
    expect($superAdminCanView)->toBeTrue()
        ->and($guestCanView)->toBeFalse();
});
