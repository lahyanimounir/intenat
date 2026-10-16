@php
    use App\Enums\StatutReclamation;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Réclamations" description="Réclamations du domaine « {{ $domaine->libelle() }} », orientées vers vous par l’application." />

    <x-carte>
        <x-carte.entete titre="Réclamations reçues">
            <x-slot:actions>
                <x-filtre libelle="Statut" wire:model.live="statut" :options="StatutReclamation::options()" tous="toutes" :actif="$statut !== ''" />
            </x-slot:actions>
        </x-carte.entete>

        @if ($reclamations->isEmpty())
            <x-vide titre="Aucune réclamation">{{ $statut !== '' ? 'Aucune réclamation ne correspond à ce statut.' : 'Les réclamations déposées par les stagiaires dans votre domaine apparaîtront ici.' }}</x-vide>
        @else
            <ul wire:loading.class="opacity-60" wire:target="statut,previousPage,nextPage">
                @foreach ($reclamations as $reclamation)
                    <li wire:key="reclamation-{{ $reclamation->id }}" class="border-b border-bord-doux px-5 py-4 last:border-0">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-base font-medium">
                                    {{ $reclamation->stagiaire->nom }} {{ $reclamation->stagiaire->prenom }}
                                    <span class="font-normal text-discret">· {{ $reclamation->internat->nom }}</span>
                                </p>
                                <p class="text-sm text-discret">Déposée le {{ Format::dateHeure($reclamation->date_creation) }}</p>
                            </div>
                            <x-statut :etat="$reclamation->statut" />
                        </div>
                        <p class="mt-2 text-base whitespace-pre-line text-encre-2">{{ $reclamation->description }}</p>
                        @if ($reclamation->reponse)
                            <div class="mt-3 rounded-md border border-bord bg-surface-2 px-3.5 py-2.5">
                                <p class="text-xs text-discret">Réponse de {{ $reclamation->traitant?->nomComplet() }} · {{ Format::dateHeure($reclamation->date_traitement) }}</p>
                                <p class="mt-0.5 text-base whitespace-pre-line">{{ $reclamation->reponse }}</p>
                            </div>
                        @endif
                        @if ($reclamation->statut !== StatutReclamation::Traitee)
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($reclamation->statut === StatutReclamation::Ouverte)
                                    <x-bouton taille="sm" wire:click="prendreEnCharge({{ $reclamation->id }})" cible="prendreEnCharge({{ $reclamation->id }})">Prendre en charge</x-bouton>
                                @endif
                                <x-bouton taille="sm" variante="primaire" wire:click="repondre({{ $reclamation->id }})">Répondre et clore</x-bouton>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <x-pagination :lignes="$reclamations" />
    </x-carte>

    @if ($dialogue === 'reponse' && $enReponse)
        <x-dialogue titre="Répondre à la réclamation" :description="$enReponse->stagiaire->nom.' '.$enReponse->stagiaire->prenom">
            <form wire:submit="clore" id="form-reponse" novalidate class="space-y-4">
                <p class="rounded-md border border-bord bg-surface-2 px-3.5 py-2.5 text-base whitespace-pre-line text-encre-2">{{ $enReponse->description }}</p>
                <x-zone-texte libelle="Réponse au stagiaire" wire:model="reponse" rows="5" maxlength="2000" requis autofocus aide="La réponse clôt la réclamation ; le stagiaire en est prévenu." />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-reponse" variante="primaire" icone="send" cible="clore">Envoyer la réponse</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
