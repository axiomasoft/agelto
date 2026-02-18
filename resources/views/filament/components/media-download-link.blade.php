@props(['file', 'label' => 'Скачать', 'url' => null])

@if($file && $url)
    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()" {{ $attributes->merge(['class' => 'text-primary-600 hover:underline']) }}>
        {{ $label }}
    </a>
@else
    —
@endif
