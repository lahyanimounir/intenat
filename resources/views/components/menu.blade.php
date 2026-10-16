@props(['declencheur', 'largeur' => 'min-w-48', 'position' => 'bottom-end'])
{{-- Menu déroulant : ouvert au clic, fermé par Échap, par un clic extérieur ou par le choix d'une entrée. --}}
<div x-data="{ ouvert: false }" class="relative" x-on:keydown.escape="ouvert = false" x-on:click.outside="ouvert = false">
    <div x-ref="declencheur" x-on:click="ouvert = ! ouvert" x-bind:data-ouvert="ouvert">{{ $declencheur }}</div>
    <div x-show="ouvert" x-cloak x-anchor.{{ $position }}.offset.6="$refs.declencheur" role="menu"
        {{ $attributes->class(['z-50 animate-[apparition_120ms_ease-out] rounded-md border border-bord bg-surface p-1 shadow-md', $largeur]) }}>
        {{ $slot }}
    </div>
</div>
