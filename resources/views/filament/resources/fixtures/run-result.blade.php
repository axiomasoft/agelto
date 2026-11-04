{{--
    Generic-рендер сводки результата: вёрстка знает только форму DTO `AnalysisSummary`
    — заголовок и плоский список пар «подпись → значение». О предмете разбора (сметы и что угодно
    другое) вьюха не знает ничего: содержимое собрал анализатор типа.

    Состояние — либо `message` с причиной отсутствия результата, либо сводка.
--}}
@php
    /** @var array<string, mixed> $state */
    $state = $getState() ?? [];
@endphp

<div class="fi-sc-component">
    @if (filled($state['message'] ?? null))
        <p class="fi-color-gray text-sm">{{ $state['message'] }}</p>
    @else
        <p class="text-sm font-semibold">{{ $state['title'] ?? '' }}</p>

        <dl class="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-2">
            @foreach ($state['metrics'] ?? [] as $metric)
                <div class="flex items-baseline justify-between gap-2">
                    <dt class="fi-color-gray text-sm">{{ $metric['label'] }}</dt>
                    <dd class="text-sm font-medium">
                        {{ $metric['value'] }}@if (filled($metric['unit'])) {{ $metric['unit'] }}@endif
                    </dd>
                </div>
            @endforeach
        </dl>

        @if (($state['hidden'] ?? 0) > 0)
            <p class="fi-color-gray mt-2 text-xs">
                Показаны первые {{ count($state['metrics']) }} строк из {{ $state['total'] }} — остальные скрыты.
            </p>
        @endif

        @if (filled($state['url'] ?? null))
            <p class="mt-2 text-sm">
                <a href="{{ $state['url'] }}" target="_blank" class="fi-link">Открыть разбор целиком</a>
            </p>
        @endif
    @endif
</div>
