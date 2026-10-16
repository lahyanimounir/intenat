@props(['actif' => false, 'compteur' => null, 'href' => null])
@php
    $classes = '-mb-px flex items-center gap-2 border-b-2 pt-1 pb-2.5 text-base font-medium whitespace-nowrap transition-colors '
        .($actif ? 'border-marine text-marine' : 'border-transparent text-discret hover:text-encre');
@endphp
{{-- Onglet : l'état actif est tenu par le composant Livewire (propriété reprise dans l'adresse). --}}
@if ($href)
    <a href="{{ $href }}" wire:navigate role="tab" aria-selected="{{ $actif ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>
        {{ $slot }}
        @if ($compteur !== null)<span class="chiffres rounded-sm bg-neutre-fond px-1.5 text-xs font-medium text-neutre">{{ $compteur }}</span>@endif
    </a>
@else
    <button type="button" role="tab" aria-selected="{{ $actif ? 'true' : 'false' }}" {{ $attributes->class($classes) }}>
        {{ $slot }}
        @if ($compteur !== null)<span class="chiffres rounded-sm bg-neutre-fond px-1.5 text-xs font-medium text-neutre">{{ $compteur }}</span>@endif
    </button>
@endif
