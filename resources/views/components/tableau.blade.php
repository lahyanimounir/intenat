@props([
    'legende',
    'entete',
    // Lignes affichées : pagination Laravel, collection ou tableau. Sert à détecter l'absence de résultat et à paginer.
    'lignes' => null,
    'barre' => null,
    'pied' => null,
    'vide' => 'Aucun résultat',
    'videDescription' => null,
    'videAction' => null,
    // Tri courant, transmis aux colonnes triables.
    'tri' => null,
    'sens' => 'asc',
    'page' => 'page',
    'plafonne' => true,
])
@php
    $pagine = $lignes instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;
    $nombre = $lignes === null ? null : ($pagine ? $lignes->total() : count($lignes));
@endphp
{{-- Tableau administratif : pagination et tri côté serveur, en-tête fixe et défilement horizontal maîtrisé. --}}
<div {{ $attributes->class('overflow-hidden rounded-lg border border-bord bg-surface shadow-xs') }}>
    @if ($barre)
        <div class="non-imprime flex flex-wrap items-center gap-2 border-b border-bord-doux px-3 py-2.5">{{ $barre }}</div>
    @endif

    <div @class(['defilement-fin overflow-auto', 'max-h-[calc(100dvh-16rem)]' => $plafonne])>
        <table class="w-full border-collapse text-left text-sm">
            <caption class="sr-only">{{ $legende }}</caption>
            <thead class="sticky top-0 z-10 bg-surface-2">
                <tr>{{ $entete }}</tr>
            </thead>
            <tbody wire:loading.class="opacity-60" class="transition-opacity">
                @if ($nombre === 0)
                    <tr>
                        <td colspan="100">
                            <x-vide :titre="$vide" :action="$videAction">{{ $videDescription }}</x-vide>
                        </td>
                    </tr>
                @else
                    {{ $slot }}
                @endif
            </tbody>
        </table>
    </div>

    @if ($pagine && $lignes->total() > 0)
        <div class="non-imprime flex flex-wrap items-center justify-between gap-3 border-t border-bord-doux bg-surface-2 px-3 py-2 text-sm text-discret">
            <p aria-live="polite" class="chiffres">{{ $lignes->firstItem() ?? 0 }}–{{ $lignes->lastItem() ?? 0 }} sur {{ \App\Support\Affichage\Format::nombre($lignes->total()) }}</p>
            <div class="flex items-center gap-1">
                <x-bouton taille="sm" variante="discret" icone="chevron-left" :disabled="$lignes->onFirstPage()" wire:click="previousPage('{{ $page }}')">Précédent</x-bouton>
                <span class="chiffres px-2">Page {{ $lignes->currentPage() }} / {{ $lignes->lastPage() }}</span>
                <x-bouton taille="sm" variante="discret" icone-fin="chevron-right" :disabled="! $lignes->hasMorePages()" wire:click="nextPage('{{ $page }}')">Suivant</x-bouton>
            </div>
        </div>
    @elseif ($pied)
        <div class="border-t border-bord-doux bg-surface-2 px-3 py-2 text-sm text-discret">{{ $pied }}</div>
    @endif
</div>
