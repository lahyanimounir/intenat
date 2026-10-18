@php
    use App\Enums\StatutPaiement;
    use App\Support\Affichage\Format;

    $statuts = [
        StatutPaiement::EnAttente->value.','.StatutPaiement::Autorise->value => 'En attente de paiement',
        StatutPaiement::RecuDepose->value => 'Justificatif à vérifier',
        StatutPaiement::Valide->value => 'Paiement validé',
        StatutPaiement::Refuse->value => 'Justificatif rejeté',
    ];
    $plusieurs = $internats->count() > 1;
@endphp
<div>
    <x-page.entete titre="Suivi des paiements"
        description="Frais d’internat mois par mois : le montant mensuel de la campagne est dû pour chaque mois de séjour. Le stagiaire paie directement et dépose son justificatif, que le gestionnaire vérifie ; aucune autorisation préalable. Le paiement ne conditionne ni l’affectation, ni l’arrivée. Aucun paiement en ligne. Les frais d’inscription en formation relèvent de l’établissement et ne sont pas suivis ici." />

    <x-tableau legende="Paiements" :lignes="$paiements"
        :vide="$filtresActifs ? 'Aucun paiement ne correspond aux filtres' : 'Aucun paiement'"
        vide-description="La mensualité du premier mois est ouverte à l’affectation du lit, puis celle de chaque mois le 1er, pour les résidents hébergés.">
        <x-slot:barre>
            <x-recherche placeholder="Nom, CEF ou n° de dossier" libelle="Rechercher un paiement" />
            <x-filtre libelle="Statut" wire:model.live="statut" :options="$statuts" :actif="$statut !== ''" />
            @if ($plusieurs)
                <input type="month" aria-label="Mois" wire:model.live="periode"
                    @class(['h-8 rounded-md border bg-surface px-2.5 text-sm shadow-xs hover:border-marine-200', 'border-marine-200 font-medium text-marine' => $periode !== '', 'border-bord text-encre-2' => $periode === '']) />
                <x-filtre libelle="Internat" wire:model.live="internat" :options="$internats" :actif="$internat !== ''" />
            @endif
            @if ($filtresActifs)
                <x-bouton taille="sm" variante="discret" icone="x" wire:click="effacerFiltres">Effacer les filtres</x-bouton>
            @endif
        </x-slot:barre>

        <x-slot:entete>
            <x-tableau.colonne>Stagiaire</x-tableau.colonne>
            @if ($plusieurs)<x-tableau.colonne>Internat</x-tableau.colonne>@endif
            <x-tableau.colonne>Mois</x-tableau.colonne>
            <x-tableau.colonne droite>Montant</x-tableau.colonne>
            <x-tableau.colonne>Statut</x-tableau.colonne>
            <x-tableau.colonne>Suivi</x-tableau.colonne>
            <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
        </x-slot:entete>

        @foreach ($paiements as $paiement)
            @php $personne = $paiement->demande->stagiaire; @endphp
            <x-tableau.ligne wire:key="paiement-{{ $paiement->id }}">
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
                    @if ($paiement->date_depot)Justificatif déposé le {{ Format::date($paiement->date_depot) }}@else Justificatif non déposé @endif @if ($paiement->date_validation) · vérifié le {{ Format::date($paiement->date_validation) }}@endif @if ($paiement->motif_refus) · rejet : {{ $paiement->motif_refus }}@endif
                </x-tableau.cellule>
                <x-tableau.cellule droite>
                    <div class="flex justify-end gap-1.5">
                        @if ($paiement->recu_fichier)
                            <x-bouton taille="sm" variante="discret" icone="eye" wire:click="voirRecu({{ $paiement->id }})">Justificatif</x-bouton>
                        @endif
                        @if ($paiement->statut === StatutPaiement::RecuDepose && $peutControler)
                            <x-bouton taille="sm" variante="danger-discret" icone="x"
                                x-on:click="$motif({ titre: 'Rejeter le justificatif', description: 'Le stagiaire verra le motif et pourra déposer un justificatif corrigé.', libelle: 'Motif du rejet', libelleAction: 'Rejeter le justificatif', destructif: true }).then((motif) => $wire.refuser({{ $paiement->id }}, motif))">
                                Rejeter
                            </x-bouton>
                            <x-bouton taille="sm" variante="primaire" icone="check" wire:click="valider({{ $paiement->id }})" cible="valider({{ $paiement->id }})">Valider</x-bouton>
                        @endif
                    </div>
                </x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>

    @if ($dialogue === 'recu' && $choisi)
        <x-apercu :chemin="route('paiements.recu', $choisi)" :nom="$choisi->recu_nom_original"
            :titre="'Justificatif — '.$choisi->demande->stagiaire->nom.' '.$choisi->demande->stagiaire->prenom.', '.Format::mois($choisi->periode)" />
    @endif
</div>
