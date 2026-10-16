@php
    use App\Enums\DomaineReclamation;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Réclamations" description="Signalez un problème d’hébergement ou de restauration, ou déposez un recours sur la sélection. Chaque réclamation reçoit une réponse.">
        <x-slot:actions><x-bouton variante="primaire" icone="plus" wire:click="ouvrirDialogue('reclamation')">Nouvelle réclamation</x-bouton></x-slot:actions>
    </x-page.entete>

    @if ($reclamations->isEmpty())
        <x-carte><x-vide titre="Aucune réclamation" icone="message-square-warning">Vos réclamations et les réponses qui leur sont apportées apparaîtront ici.</x-vide></x-carte>
    @else
        <div class="space-y-4">
            @foreach ($reclamations as $reclamation)
                <x-carte wire:key="reclamation-{{ $reclamation->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-discret">{{ $reclamation->domaine->libelle() }} · déposée le {{ Format::dateHeure($reclamation->date_creation) }}</p>
                            <p class="mt-1 text-base whitespace-pre-line text-encre">{{ $reclamation->description }}</p>
                        </div>
                        <x-statut :etat="$reclamation->statut" />
                    </div>
                    @if ($reclamation->reponse)
                        <div class="border-t border-bord-doux bg-surface-2 px-5 py-3">
                            <p class="text-sm font-medium text-encre">Réponse @if ($reclamation->date_traitement)<span class="font-normal text-discret">· {{ Format::dateHeure($reclamation->date_traitement) }}</span>@endif</p>
                            <p class="mt-0.5 text-base whitespace-pre-line text-encre-2">{{ $reclamation->reponse }}</p>
                        </div>
                    @endif
                </x-carte>
            @endforeach
        </div>
    @endif

    @if ($dialogue === 'reclamation')
        <x-dialogue titre="Nouvelle réclamation" description="Elle sera transmise au responsable du domaine choisi.">
            <div class="space-y-4">
                <x-liste libelle="Domaine" wire:model="domaine" :options="DomaineReclamation::options()" vide="Choisir…" requis />
                <x-zone-texte libelle="Description" wire:model="description" rows="5" requis autofocus aide="Dix caractères au moins. Décrivez les faits précisément." />
            </div>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton variante="primaire" wire:click="deposer" cible="deposer">Envoyer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
