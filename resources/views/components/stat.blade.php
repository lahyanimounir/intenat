@props(['libelle', 'valeur', 'detail' => null, 'href' => null, 'ton' => 'neutre'])
@php
    $tons = ['neutre' => 'text-encre', 'alerte' => 'text-alerte', 'danger' => 'text-danger', 'succes' => 'text-succes'];
    $base = 'block rounded-lg border border-bord bg-surface px-4 py-3.5 shadow-xs';
    $balise = $href ? 'a' : 'div';
@endphp
{{-- Indicateur chiffré. Devient un lien quand il mène à la liste qu'il compte. --}}
<{{ $balise }} @if ($href) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([$base, 'transition-colors hover:border-marine-200 hover:bg-marine-50/50' => $href]) }}>
    <p class="text-sm text-discret">{{ $libelle }}</p>
    <p class="chiffres mt-1 text-2xl leading-tight font-semibold {{ $tons[$ton] }}">{{ $valeur }}</p>
    @if ($detail)<p class="mt-1 text-sm text-discret">{{ $detail }}</p>@endif
</{{ $balise }}>
