@props(['libelle' => 'Rechercher', 'placeholder' => 'Rechercher…', 'modele' => 'recherche'])
{{-- Champ de recherche à saisie différée : une requête au plus toutes les 300 ms. --}}
<div class="relative w-full max-w-64" x-data="{ saisie: $wire.entangle('{{ $modele }}').live }">
    <x-icone nom="search" class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-discret" />
    <input type="search" aria-label="{{ $libelle }}" placeholder="{{ $placeholder }}" x-model.debounce.300ms="saisie"
        {{ $attributes->class('champ h-8 pr-8 pl-8 text-sm [&::-webkit-search-cancel-button]:hidden') }} />
    <button type="button" x-show="saisie" x-cloak x-on:click="saisie = ''" aria-label="Effacer la recherche"
        class="absolute top-1/2 right-1.5 -translate-y-1/2 rounded-sm p-1 text-discret hover:text-encre">
        <x-icone nom="x" class="size-3.5" />
    </button>
</div>
