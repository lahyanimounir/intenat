@props(['titre', 'description' => null, 'largeur' => 'md', 'fermer' => 'fermerDialogue()', 'pied' => null])
@php $largeurs = ['sm' => 'max-w-md', 'md' => 'max-w-xl', 'lg' => 'max-w-3xl', 'xl' => 'max-w-5xl']; @endphp
{{--
    Fenêtre modale pilotée par le composant Livewire : elle n'est rendue que lorsqu'elle est ouverte.
    `fermer` : appel Livewire qui la referme. Le focus y est retenu ; Échap et le voile la ferment.
--}}
<div class="fixed inset-0 z-50" x-data x-on:keydown.escape.window="$wire.{{ $fermer }}">
    <div class="absolute inset-0 animate-[fondu_120ms_ease-out] bg-encre/35" wire:click="{{ $fermer }}" aria-hidden="true"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="dialogue-titre" x-trap.noscroll.inert="true"
        {{ $attributes->class(['absolute top-1/2 left-1/2 flex max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] -translate-x-1/2 -translate-y-1/2 animate-[fondu_140ms_ease-out] flex-col rounded-lg border border-bord bg-surface shadow-md', $largeurs[$largeur]]) }}>
        <header class="flex items-start gap-4 border-b border-bord-doux px-5 py-4">
            <div class="min-w-0 flex-1">
                <h2 id="dialogue-titre" class="text-md font-semibold text-encre">{{ $titre }}</h2>
                @if ($description)<p class="mt-0.5 text-sm text-discret">{{ $description }}</p>@endif
            </div>
            <x-bouton variante="discret" taille="icone" aria-label="Fermer" class="-mt-1 -mr-2" wire:click="{{ $fermer }}"><x-icone nom="x" /></x-bouton>
        </header>
        <div class="defilement-fin min-h-0 flex-1 overflow-y-auto px-5 py-4">{{ $slot }}</div>
        @if ($pied)<footer class="flex flex-wrap items-center justify-end gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">{{ $pied }}</footer>@endif
    </div>
</div>
