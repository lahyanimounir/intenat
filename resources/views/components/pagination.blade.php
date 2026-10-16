@props(['lignes', 'page' => 'page'])
{{-- Pied de pagination d'une liste présentée hors tableau (cartes, fils de messages). --}}
@if ($lignes->hasPages())
    <div {{ $attributes->class('non-imprime flex flex-wrap items-center justify-between gap-3 border-t border-bord-doux bg-surface-2 px-3 py-2 text-sm text-discret') }}>
        <p aria-live="polite" class="chiffres">{{ $lignes->firstItem() ?? 0 }}–{{ $lignes->lastItem() ?? 0 }} sur {{ \App\Support\Affichage\Format::nombre($lignes->total()) }}</p>
        <div class="flex items-center gap-1">
            <x-bouton taille="sm" variante="discret" icone="chevron-left" :disabled="$lignes->onFirstPage()" wire:click="previousPage('{{ $page }}')">Précédent</x-bouton>
            <span class="chiffres px-2">Page {{ $lignes->currentPage() }} / {{ $lignes->lastPage() }}</span>
            <x-bouton taille="sm" variante="discret" icone-fin="chevron-right" :disabled="! $lignes->hasMorePages()" wire:click="nextPage('{{ $page }}')">Suivant</x-bouton>
        </div>
    </div>
@endif
