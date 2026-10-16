@php use App\Support\Affichage\Format; @endphp
<div class="mx-auto max-w-4xl">
    <x-page.entete titre="Notifications" :description="$total > 0 ? $total.' notification(s) non lue(s).' : 'Toutes vos notifications sont lues.'">
        @if ($total > 0)
            <x-slot:actions><x-bouton icone="check" wire:click="lire" cible="lire">Tout marquer comme lu</x-bouton></x-slot:actions>
        @endif
    </x-page.entete>

    <x-carte>
        <div class="flex items-center justify-between gap-3 border-b border-bord-doux px-5 py-3">
            <x-case wire:model.live="nonLues" libelle="Afficher seulement les non lues" />
        </div>

        @if ($notifications->isEmpty())
            <x-vide titre="Aucune notification" icone="bell-off">{{ $nonLues ? 'Vous n’avez aucune notification non lue.' : 'Les messages qui vous sont adressés apparaîtront ici.' }}</x-vide>
        @else
            <ul wire:loading.class="opacity-60">
                @foreach ($notifications as $notification)
                    <li class="flex items-start gap-3 border-b border-bord-doux px-5 py-3.5 last:border-0" wire:key="notification-{{ $notification->id }}">
                        <span aria-hidden="true" @class(['mt-2 size-1.5 shrink-0 rounded-full', 'bg-bleu' => ! $notification->lue, 'bg-transparent' => $notification->lue])></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-discret">{{ $notification->type->libelle() }} · {{ Format::dateHeure($notification->date_envoi) }}</p>
                            <p @class(['mt-0.5 text-base text-encre', 'font-medium' => ! $notification->lue])>{{ $notification->titre }}</p>
                            <p class="mt-0.5 text-base text-encre-2">{{ $notification->message }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            @if ($notification->lien)
                                <x-bouton taille="sm" variante="discret" icone-fin="chevron-right" wire:click="ouvrir({{ $notification->id }})">Ouvrir</x-bouton>
                            @endif
                            @if (! $notification->lue)
                                <x-bouton taille="icone" variante="discret" aria-label="Marquer comme lue" wire:click="lire({{ $notification->id }})"><x-icone nom="check" /></x-bouton>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($notifications->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-bord-doux bg-surface-2 px-3 py-2 text-sm text-discret">
                <p class="chiffres">{{ $notifications->firstItem() }}–{{ $notifications->lastItem() }} sur {{ Format::nombre($notifications->total()) }}</p>
                <div class="flex items-center gap-1">
                    <x-bouton taille="sm" variante="discret" icone="chevron-left" :disabled="$notifications->onFirstPage()" wire:click="previousPage">Précédent</x-bouton>
                    <span class="chiffres px-2">Page {{ $notifications->currentPage() }} / {{ $notifications->lastPage() }}</span>
                    <x-bouton taille="sm" variante="discret" icone-fin="chevron-right" :disabled="! $notifications->hasMorePages()" wire:click="nextPage">Suivant</x-bouton>
                </div>
            </div>
        @endif
    </x-carte>
</div>
