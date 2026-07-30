<x-filament-panels::page>
    {{ $this->table }}

    @php($call = $this->selectedCall())

    @if ($call)
        <div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold">
                {{ $call->purpose }} · {{ $call->model }} · {{ $call->called_at?->toDateTimeString() }}
            </h2>

            <div class="mt-4 grid gap-6 md:grid-cols-2">
                <div>
                    <h3 class="mb-2 text-sm font-medium">Request</h3>
                    @include('filament.pages.partials.final-prompt-payload', ['payload' => $this->requestPayload($call)])
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-medium">Response</h3>
                    @include('filament.pages.partials.final-prompt-payload', ['payload' => $this->responsePayload($call)])
                </div>
            </div>
        </div>
    @else
        <p class="mt-6 text-sm text-gray-500">Выберите вызов в таблице выше, чтобы увидеть его промт.</p>
    @endif
</x-filament-panels::page>
