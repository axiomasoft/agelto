<x-filament-panels::page>
    {{ $this->infolist }}

    @php($document = $this->goldDocument())

    @if ($document)
        @foreach ($document->summaries() as $gold)
            <x-filament::section :heading="'Смета '.($gold['number'] ?? 'без номера')">
                <div class="grid gap-4 text-sm md:grid-cols-2">
                    @foreach (['estimate_number', 'currency', 'base_year', 'price_level', 'regulatory_base', 'rows_expected', 'calc_method'] as $field)
                        <div>
                            <span class="font-medium">{{ \App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\GoldContentSchema::FIELD_LABELS[$field] }}:</span>
                            {{ $gold['fields'][$field] ?? '—' }}
                        </div>
                    @endforeach
                </div>

                @if ($gold['totals'] !== [])
                    <div class="mt-4">
                        <h3 class="font-medium">Итоги</h3>
                        <ul class="mt-2 list-inside list-disc text-sm">
                            @foreach ($gold['totals'] as $total)
                                <li>{{ $total['label'] }}: {{ $total['value'] ?? ($total['rate'] === null ? '—' : $total['rate'].'%') }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-filament::section>
        @endforeach

        <div class="mt-6">{{ $this->table }}</div>
    @else
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $this->emptyStateMessage() }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
