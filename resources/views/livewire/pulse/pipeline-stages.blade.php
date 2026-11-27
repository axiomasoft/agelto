<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Стадии за 24ч" details="prc_documents / prc_ai_analyses">
        <x-slot:icon>
            <x-pulse::icons.rocket-launch />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        @if ($stages->sum('succeeded') === 0 && $stages->sum('failed') === 0)
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Стадия</x-pulse::th>
                        <x-pulse::th class="text-right">Успех</x-pulse::th>
                        <x-pulse::th class="text-right">Неуспех</x-pulse::th>
                        <x-pulse::th class="text-right">Failure rate</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($stages as $row)
                        <tr wire:key="{{ $row['stage'] }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $row['stage'] }}-row">
                            <x-pulse::td>{{ $row['stage'] }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($row['succeeded']) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($row['failed']) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ $row['failure_rate'] }}%</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
