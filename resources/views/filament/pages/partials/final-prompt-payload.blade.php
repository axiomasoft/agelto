@if ($payload->available)
    <pre class="max-h-96 overflow-auto rounded-lg bg-gray-950/5 p-4 text-xs dark:bg-white/5">{{ $payload->content }}</pre>

    @if ($payload->truncated)
        <p class="mt-2 text-xs text-warning-600">Показана часть содержимого — payload обрезан лимитом чтения.</p>
    @endif
@else
    <p class="text-sm text-gray-500">{{ $payload->unavailableReason }}</p>
@endif
