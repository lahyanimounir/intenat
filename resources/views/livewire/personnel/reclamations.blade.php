@php
    use App\Enums\StatutReclamation;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Réclamations"
        :description="$tous
            ? 'Suivi de toutes les réclamations de votre périmètre, y compris clôturées : message du stagiaire, réponse, état et historique. Vous traitez celles du domaine « '.$domaineTraite->libelle().' » ; les autres se consultent.'
            : 'Réclamations du domaine « '.$domaineTraite->libelle().' », orientées vers vous par l’application.'" />

    <x-carte>
        <x-carte.entete titre="Réclamations reçues">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <x-recherche modele="stagiaire" placeholder="Stagiaire : nom ou CEF" libelle="Rechercher un stagiaire" />
                    <x-filtre libelle="Statut" wire:model.live="statut" :options="$statuts" tous="toutes" :actif="$statut !== ''" />
                    @if ($tous)
                        <x-filtre libelle="Domaine" wire:model.live="domaine" :options="$domaines" :actif="$domaine !== ''" />
                    @endif
                    @if ($filtresActifs)
                        <x-bouton taille="sm" variante="discret" icone="x" wire:click="effacerFiltres">Effacer les filtres</x-bouton>
                    @endif
                </div>
            </x-slot:actions>
        </x-carte.entete>

        @if ($reclamations->isEmpty())
            <x-vide titre="Aucune réclamation">{{ $filtresActifs ? 'Aucune réclamation ne correspond à ces filtres.' : 'Les réclamations déposées par les stagiaires apparaîtront ici.' }}</x-vide>
        @else
            <ul wire:loading.class="opacity-60" wire:target="statut,domaine,stagiaire,previousPage,nextPage">
                @foreach ($reclamations as $reclamation)
                    @php $traitable = $reclamation->domaine === $domaineTraite; @endphp
                    <li wire:key="reclamation-{{ $reclamation->id }}" class="border-b border-bord-doux px-5 py-4 last:border-0">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-base font-medium">
                                    {{ $reclamation->stagiaire->nom }} {{ $reclamation->stagiaire->prenom }}
                                    <span class="font-normal text-discret">· {{ $reclamation->internat->nom }}</span>
                                </p>
                                <p class="text-sm text-discret">
                                    Déposée le {{ Format::dateHeure($reclamation->date_creation) }}
                                    @if ($tous) · <x-badge>{{ $reclamation->domaine->libelle() }}</x-badge>@endif
                                </p>
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
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-bouton taille="sm" variante="discret" icone="rotate-ccw-clock" wire:click="voirHistorique({{ $reclamation->id }})">Historique</x-bouton>
                            @if ($traitable && $reclamation->statut !== StatutReclamation::Traitee)
                                @if ($reclamation->statut === StatutReclamation::Ouverte)
                                    <x-bouton taille="sm" wire:click="prendreEnCharge({{ $reclamation->id }})" cible="prendreEnCharge({{ $reclamation->id }})">Prendre en charge</x-bouton>
                                @endif
                                <x-bouton taille="sm" variante="primaire" wire:click="repondre({{ $reclamation->id }})">Répondre et clore</x-bouton>
                            @elseif (! $traitable)
                                <span class="self-center text-sm text-discret">Consultation seule : traitée par le responsable du domaine.</span>
                            @endif
                        </div>
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
                <x-zone-texte libelle="Réponse au stagiaire" wire:model="reponse" rows="5" maxlength="2000" requis autofocus aide="La réponse clôt la réclamation ; le stagiaire en est prévenu. Elle reste consultable ensuite." />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-reponse" variante="primaire" icone="send" cible="clore">Envoyer la réponse</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'historique' && $enHistorique)
        <x-dialogue titre="Historique de la réclamation"
            :description="$enHistorique->stagiaire->nom.' '.$enHistorique->stagiaire->prenom.' · '.$enHistorique->internat->nom.' · '.$enHistorique->domaine->libelle()">
            <div class="space-y-4">
                <div class="flex items-center gap-2 text-sm text-discret">État actuel : <x-statut :etat="$enHistorique->statut" /></div>
                <ol class="space-y-3 border-l border-bord pl-4">
                    @foreach ($historique as $etape)
                        <li>
                            <p class="text-sm font-medium text-encre">{{ $etape['evenement'] }}</p>
                            <p class="text-xs text-discret">{{ Format::dateHeure($etape['date']) }} · {{ $etape['acteur'] }}</p>
                            @if ($etape['texte'])
                                <p class="mt-1 rounded-md border border-bord bg-surface-2 px-3 py-2 text-sm whitespace-pre-line text-encre-2">{{ $etape['texte'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
            <x-slot:pied><x-bouton wire:click="fermerDialogue">Fermer</x-bouton></x-slot:pied>
        </x-dialogue>
    @endif
</div>
