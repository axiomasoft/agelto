<x-pulse>
    {{-- EST-AI P7.5 (D25) — кастомные карточки пайплайна, SQL-агрегаты по prc_* --}}
    <livewire:pulse.pipeline-stages cols="6" />

    <livewire:pulse.llm-spend cols="6" />

    <livewire:pulse.process-platform-health cols="full" />

    <livewire:pulse.servers cols="full" />

    <livewire:pulse.usage cols="4" rows="2" />

    <livewire:pulse.queues cols="4" />

    <livewire:pulse.cache cols="4" />

    <livewire:pulse.slow-queries cols="8" />

    <livewire:pulse.exceptions cols="6" />

    <livewire:pulse.slow-requests cols="6" />

    <livewire:pulse.slow-jobs cols="6" />

    <livewire:pulse.slow-outgoing-requests cols="6" />
</x-pulse>
