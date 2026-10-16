@props(['ton' => 'info', 'titre' => null, 'action' => null])
@php
    $tons = [
        'info' => 'border-info-bord bg-info-fond',
        'succes' => 'border-succes-bord bg-succes-fond',
        'alerte' => 'border-alerte-bord bg-alerte-fond',
        'danger' => 'border-danger-bord bg-danger-fond',
        'neutre' => 'border-bord bg-surface-2',
    ];
    $icones = ['info' => 'info', 'succes' => 'circle-check', 'alerte' => 'triangle-alert', 'danger' => 'circle-alert', 'neutre' => 'info'];
    $couleurs = ['info' => 'text-info', 'succes' => 'text-succes', 'alerte' => 'text-alerte', 'danger' => 'text-danger', 'neutre' => 'text-discret'];
@endphp
<div role="{{ $ton === 'danger' ? 'alert' : 'status' }}" {{ $attributes->class(['flex gap-3 rounded-md border px-3.5 py-3 text-sm text-encre', $tons[$ton]]) }}>
    <x-icone :nom="$icones[$ton]" class="mt-0.5 {{ $couleurs[$ton] }}" />
    <div class="min-w-0 flex-1">
        @if ($titre)<p class="font-medium text-encre">{{ $titre }}</p>@endif
        @if ($slot->isNotEmpty())<div @class(['text-encre-2', 'mt-0.5' => $titre])>{{ $slot }}</div>@endif
    </div>
    @if ($action)<div class="shrink-0">{{ $action }}</div>@endif
</div>
