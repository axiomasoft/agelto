<x-filament-panels::page>
    <x-filament::section heading="Выбор шага">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="fi-fo-field-wrp-label">
                <span class="fi-fo-field-wrp-label">Тип документа</span>
                <select wire:model.live="documentType" class="fi-select-input mt-1 block w-full rounded-lg">
                    @foreach ($this->documentTypeOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="fi-fo-field-wrp-label">
                <span class="fi-fo-field-wrp-label">Шаг</span>
                <select wire:model.live="step" class="fi-select-input mt-1 block w-full rounded-lg">
                    @foreach ($this->stepOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </x-filament::section>

    @php($step = $this->selectedPromptStep())
    @php($composed = $this->composedPrompt())
    @php($prompts = $this->participatingPrompts())

    <x-filament::section class="mt-6" heading="Состав правил сейчас">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Цепочка композиции: <span class="font-mono">{{ implode(' → ', array_map(static fn ($chainStep) => $chainStep->value, $step->compositionChain())) }}</span>
        </p>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="p-2">Ключ</th>
                        <th class="p-2">Шаг</th>
                        <th class="p-2">Версия</th>
                        <th class="p-2">Приоритет</th>
                        <th class="p-2">Канон</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($prompts as $prompt)
                        <tr class="border-b">
                            <td class="p-2 font-mono">{{ $prompt->key }}</td>
                            <td class="p-2 font-mono">{{ $prompt->step }}</td>
                            <td class="p-2">{{ $prompt->version }}</td>
                            <td class="p-2">{{ $prompt->priority }}</td>
                            <td class="p-2">{{ $prompt->is_protected ? 'Да' : 'Нет' }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-2" colspan="5">Активных правил для этой композиции нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <h3 class="mt-6 text-base font-semibold">Склеенный текст правил</h3>
        <pre class="mt-3 whitespace-pre-wrap rounded-lg bg-gray-950 p-4 font-mono text-sm text-gray-100">{{ $composed->systemPart }}</pre>

        <h3 class="mt-6 text-base font-semibold">Карта версий</h3>
        <pre class="mt-3 whitespace-pre-wrap rounded-lg bg-gray-100 p-4 font-mono text-sm dark:bg-gray-900">{{ json_encode($composed->promptVersionSet, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </x-filament::section>

    <x-filament::section class="mt-6" heading="Граница этого экрана">
        <p class="text-sm text-gray-700 dark:text-gray-300">
            Здесь показана только часть правил, собранная сейчас без блока схем: этот блок добавляет место вызова,
            поэтому экран не воспроизводит текст, реально ушедший в модель. Для сохранённого текста конкретного
            вызова <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource::getUrl() }}">откройте анализ и «Финальный промт прогона»</a>.
        </p>
    </x-filament::section>
</x-filament-panels::page>
