@php
    use App\Enums\StatutPaiement;
    use App\Support\Affichage\Format;

    $statuts = [
        StatutPaiement::EnAttente->value => 'En attente d’autorisation',
        StatutPaiement::Autorise->value => 'Autorisé, reçu attendu',
        StatutPaiement::RecuDepose->value => 'Reçu à contrôler',
        StatutPaiement::Valide->value => 'Validé',
        StatutPaiement::Refuse->value => 'Reçu refusé',
    ];
    $plusieurs = $internats->count() > 1;
@endphp
<div>
    <x-page.entete titre="Suivi des paiements"
        description="Frais d’internat période par période. Trois étapes distinctes : l’autorisation de paiement (chef de pôle), le dépôt du reçu (stagiaire), son contrôle (gestionnaire). Aucun paiement en ligne. Les frais d’inscription en formation relèvent de l’établissement et ne sont pas suivis ici." />

    <x-tableau legende="Paiements" :lignes="$paiements"
        :vide="$filtresActifs ? 'Aucun paiement ne correspond aux filtres' : 'Aucun paiement'"
        vide-description="Un paiement est créé quand un admis confirme sa place, puis chaque mois pour les résidents.">
        <x-slot:barre>
            <x-recherche placeholder="Nom, CEF ou n° de dossier" libelle="Rechercher un paiement" />
            <x-filtre libelle="Statut" wire:model.live="statut" :options="$statuts" :actif="$statut !== ''" />
            <input type="month" aria-label="Période" wire:model.live="periode"
                @class(['h-8 rounded-md border bg-surface px-2.5 text-sm shadow-xs hover:border-marine-200', 'border-marine-200 font-medium text-marine' => $periode !== '', 'border-bord text-encre-2' => $periode === '']) />
            @if ($plusieurs)
                <x-filtre libelle="Internat" wire:model.live="internat" :options="$internats" :actif="$internat !== ''" />
            @endif
            @if ($filtresActifs)
                <x-bouton taille="sm" variante="discret" icone="x" wire:click="effacerFiltres">Effacer les filtres</x-bouton>
            @endif
            @if ($peutAutoriser && count($selection) > 0)
                <x-bouton taille="sm" variante="primaire" class="ml-auto" wire:click="autoriserSelection" cible="autoriserSelection">
                    Autoriser les {{ count($selection) }} paiement(s) sélectionné(s)
                </x-bouton>
            @endif
        </x-slot:barre>

        <x-slot:entete>
            @if ($peutAutoriser)<x-tableau.colonne class="w-10"><span class="sr-only">Sélection</span></x-tableau.colonne>@endif
            <x-tableau.colonne>Stagiaire</x-tableau.colonne>
            @if ($plusieurs)<x-tableau.colonne>Internat</x-tableau.colonne>@endif
            <x-tableau.colonne>Période</x-tableau.colonne>
            <x-tableau.colonne droite>Montant</x-tableau.colonne>
            <x-tableau.colonne>Statut</x-tableau.colonne>
            <x-tableau.colonne>Suivi</x-tableau.colonne>
            <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
        </x-slot:entete>

        @foreach ($paiements as $paiement)
            @php $personne = $paiement->demande->stagiaire; @endphp
            <x-tableau.ligne wire:key="paiement-{{ $paiement->id }}">
                @if ($peutAutoriser)
                    <x-tableau.cellule>
                        @if ($paiement->statut === StatutPaiement::EnAttente)
                            <x-case wire:model.live="selection" value="{{ $paiement->id }}" aria-label="Sélectionner le paiement de {{ $personne->nom }} {{ $personne->prenom }}, {{ Format::mois($paiement->periode) }}" />
                        @endif
                    </x-tableau.cellule>
                @endif
                <x-tableau.cellule>
                    <p class="font-medium text-encre">{{ $personne->nom }} {{ $personne->prenom }}</p>
                    <p class="chiffres text-xs whitespace-nowrap text-discret">{{ $paiement->demande->numero_dossier }}</p>
                </x-tableau.cellule>
                @if ($plusieurs)<x-tableau.cellule class="text-encre-2">{{ $paiement->demande->campagne->internat->nom }}</x-tableau.cellule>@endif
                <x-tableau.cellule class="whitespace-nowrap">
                    {{ Format::mois($paiement->periode) }}
                    @if ($paiement->genere_auto)<x-badge class="ml-1">Suivi mensuel</x-badge>@endif
                </x-tableau.cellule>
                <x-tableau.cellule droite class="chiffres whitespace-nowrap">{{ Format::montant($paiement->montant) }}</x-tableau.cellule>
                <x-tableau.cellule><x-statut :etat="$paiement->statut" /></x-tableau.cellule>
                <x-tableau.cellule class="chiffres max-w-xs text-xs text-discret">
                    {{ $paiement->date_autorisation ? 'Autorisé le '.Format::date($paiement->date_autorisation) : 'Autorisation à délivrer' }}@if ($paiement->date_depot) · reçu le {{ Format::date($paiement->date_depot) }}@endif @if ($paiement->motif_refus) · refus : {{ $paiement->motif_refus }}@endif
                </x-tableau.cellule>
                <x-tableau.cellule droite>
                    <div class="flex justify-end gap-1.5">
                        @if ($paiement->statut === StatutPaiement::EnAttente && $peutAutoriser)
                            <x-bouton taille="sm" variante="primaire" wire:click="preparerAutorisation({{ $paiement->id }})">Autoriser</x-bouton>
                        @endif
                        @if ($paiement->date_autorisation)
                            <x-bouton taille="sm" variante="discret" icone="download" :href="route('paiements.autorisation', $paiement)" :navigation="false" target="_blank" rel="noopener"
                                aria-label="Télécharger l’autorisation de paiement">Autorisation</x-bouton>
                        @endif
                        @if ($paiement->recu_fichier)
                            <x-bouton taille="sm" variante="discret" icone="eye" wire:click="voirRecu({{ $paiement->id }})">Reçu</x-bouton>
                        @endif
                        @if ($paiement->statut === StatutPaiement::RecuDepose && $peutControler)
                            <x-bouton taille="sm" variante="danger-discret" icone="x"
                                x-on:click="$motif({ titre: 'Refuser le reçu', description: 'Le stagiaire verra le motif et pourra déposer un nouveau reçu.', libelle: 'Motif du refus', libelleAction: 'Refuser le reçu', destructif: true }).then((motif) => $wire.refuser({{ $paiement->id }}, motif))">
                                Refuser
                            </x-bouton>
                            <x-bouton taille="sm" variante="primaire" icone="check" wire:click="valider({{ $paiement->id }})" cible="valider({{ $paiement->id }})">Valider</x-bouton>
                        @endif
                    </div>
                </x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>

    @if ($dialogue === 'autorisation' && $choisi)
        <x-dialogue titre="Délivrer l’autorisation de paiement"
            :description="$choisi->demande->stagiaire->nom.' '.$choisi->demande->stagiaire->prenom.' — dossier '.$choisi->demande->numero_dossier">
            <form wire:submit="delivrer" id="form-autorisation" novalidate class="space-y-4">
                <x-alerte ton="neutre">
                    L’autorisation n’est pas un encaissement. Le stagiaire règle selon les modalités de l’établissement, puis téléverse son reçu, que le
                    gestionnaire contrôle. L’hébergement peut être autorisé même si le reçu arrive plus tard.
                </x-alerte>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-saisie libelle="Période" type="month" wire:model="autorisation.periode" requis />
                    <x-saisie libelle="Montant dû (DH)" type="number" min="0" step="0.01" inputmode="decimal" wire:model="autorisation.montant" requis />
                </div>
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-autorisation" variante="primaire" cible="delivrer">Autoriser le paiement</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'recu' && $choisi)
        <x-apercu :chemin="route('paiements.recu', $choisi)" :nom="$choisi->recu_nom_original"
            :titre="'Reçu — '.$choisi->demande->stagiaire->nom.' '.$choisi->demande->stagiaire->prenom.', '.Format::mois($choisi->periode)" />
    @endif
</div>
