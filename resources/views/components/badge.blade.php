@props(['ton' => 'neutre', 'point' => false])
@php
    $tons = [
        'neutre' => 'border-neutre-bord bg-neutre-fond text-neutre',
        'info' => 'border-info-bord bg-info-fond text-info',
        'succes' => 'border-succes-bord bg-succes-fond text-succes',
        'alerte' => 'border-alerte-bord bg-alerte-fond text-alerte',
        'danger' => 'border-danger-bord bg-danger-fond text-danger',
        'marine' => 'border-marine-200 bg-marine-50 text-marine',
    ];
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-px text-xs font-medium whitespace-nowrap', $tons[$ton] ?? $tons['neutre']]) }}>
    @if ($point)<span aria-hidden="true" class="size-1.5 rounded-full bg-current opacity-80"></span>@endif
    {{ $slot }}
</span>
