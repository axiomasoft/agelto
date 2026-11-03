<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header name="Площадки: покрытие и процессы" details="Durable coverage / active projection generation" />
    <x-pulse::scroll :expand="$expand" wire:poll.30s="">
        @foreach ($snapshots as $snapshot)
            <section wire:key="platform-{{ $snapshot->platform->value }}" class="p-4 text-sm text-gray-700 dark:text-gray-300">
                <h3 class="font-bold">{{ $snapshot->platform->value }}</h3>
                <p>Измерено: {{ $snapshot->measuredAt->value->format(DATE_ATOM) }}</p>
                <p>Acquisition controls: {{ $snapshot->acquisitionControl?->value ?? 'unknown' }}</p>
                @if ($snapshot->pausedScopes !== [])
                    <p>Новые операции в указанных scopes остановлены; текущие завершаются.</p>
                @endif
                @foreach ($snapshot->pausedScopes as $scope)
                    <p>{{ $scope }}</p>
                @endforeach
                <p>Непрерывное покрытие по исходную дату включительно: {{ $snapshot->coveredThrough ?? 'unknown' }}</p>
                <p>acquisition_watermark_lag_seconds: {{ $snapshot->acquisitionWatermarkLag->label() }}</p>
                <p>listing_closed_complete_ratio (24h): {{ $snapshot->listingClosedCompleteRatio->label() }}</p>
                <p>Закрытые partitions: {{ $snapshot->closedPartitions }}; collecting: {{ $snapshot->collectingPartitions }}</p>
                <p>projection_lag_seconds: {{ $snapshot->projectionLag->label() }}</p>
                <p>detail_due_lag_seconds: {{ $snapshot->detailDueLag->label() }}</p>
                <p>status_refresh_due_lag_seconds: {{ $snapshot->statusRefreshDueLag->label() }}</p>
                <p>Projection: {{ $snapshot->projection?->mode->value ?? 'unknown' }}; generation: {{ $snapshot->projection?->activeGeneration ?? 'unknown' }}</p>
                @forelse ($snapshot->counters as $counter)
                    <p>{{ $counter->metricKey }} {{ $counter->dimensionKey }}: {{ $counter->value }}</p>
                @empty
                    <p>counters: unknown (no_data)</p>
                @endforelse
                @foreach ($snapshot->workCounts as $work)
                    <p>work_{{ $work->state->value }} stage={{ $work->stage->value }} reason={{ $work->reason?->value ?? 'none' }}: {{ $work->count }}</p>
                @endforeach
            </section>
        @endforeach
    </x-pulse::scroll>
</x-pulse::card>
