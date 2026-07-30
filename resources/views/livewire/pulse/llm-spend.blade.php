<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="LLM-расход" details="llm_calls, последние 24ч">
        <x-slot:icon>
            <x-pulse::icons.scale />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        @if ($rows->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Purpose</x-pulse::th>
                        <x-pulse::th class="text-right">Calls</x-pulse::th>
                        <x-pulse::th class="text-right">Prompt</x-pulse::th>
                        <x-pulse::th class="text-right">Cached</x-pulse::th>
                        <x-pulse::th class="text-right">Completion</x-pulse::th>
                        <x-pulse::th class="text-right">Cost</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="{{ $row->purpose }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $row->purpose }}-row">
                            <x-pulse::td>{{ $row->purpose }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($row->calls) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($row->promptTokens) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($row->cachedTokens) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($row->completionTokens) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">${{ number_format($row->costMicros / 1_000_000, 4) }}</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>

            <div class="mt-2 text-xs text-gray-400 text-center">
                Итого: ${{ number_format($totalCostMicros / 1_000_000, 4) }}
                ({{ number_format(($totalCostMicros / 1_000_000) * $rubPerUsd, 2) }} ₽ по курсу {{ $rubPerUsd }})
            </div>
        @endif

        {{-- Подпись источника обязательна: цифра расхода без происхождения — механизм, по которому
             расхождение трекинга с биллингом провайдера остаётся невидимым (D10). --}}
        <div class="mt-1 text-xs text-gray-400 text-center">{{ $signature }}</div>
    </x-pulse::scroll>
</x-pulse::card>
