@php
    use App\Enums\StatutInscription;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Dossiers de préinscription"
        description="Dossiers déposés par les stagiaires. Ouvrez un dossier pour contrôler ses pièces et décider : complet, à rectifier ou rejet motivé." />

    <x-tableau legende="Dossiers de préinscription" :lignes="$dossiers" :tri="$tri" :sens="$sens"
        :vide="$filtresActifs ? 'Aucun dossier ne correspond aux filtres' : 'Aucun dossier déposé'"
        :vide-description="$filtresActifs ? null : 'Les dossiers apparaissent ici dès qu’un stagiaire soumet sa demande.'">
        <x-slot:barre>
            <x-recherche placeholder="Nom, CEF ou n° de dossier" libelle="Rechercher un dossier" />
            <x-filtre libelle="Statut" wire:model.live="statut" :options="$statuts" :actif="$statut !== ''" />
            <x-filtre libelle="Campagne" wire:model.live="campagne" :options="$campagnes" tous="toutes" :actif="$campagne !== ''" />
            @if ($internats->count() > 1)
                <x-filtre libelle="Internat" wire:model.live="internat" :options="$internats" :actif="$internat !== ''" />
            @endif
            @if ($filtresActifs)
                <x-bouton taille="sm" variante="discret" icone="x" wire:click="effacerFiltres">Effacer les filtres</x-bouton>
            @endif
        </x-slot:barre>

        <x-slot:entete>
            <x-tableau.colonne cle="numero_dossier">N° de dossier</x-tableau.colonne>
            <x-tableau.colonne>Stagiaire</x-tableau.colonne>
            <x-tableau.colonne>Filière</x-tableau.colonne>
            <x-tableau.colonne>Internat</x-tableau.colonne>
            <x-tableau.colonne cle="statut">Statut</x-tableau.colonne>
            <x-tableau.colonne cle="date_soumission">Déposé le</x-tableau.colonne>
        </x-slot:entete>

        @foreach ($dossiers as $dossier)
            <x-tableau.ligne :href="route('dossiers.fiche', $dossier)" wire:key="dossier-{{ $dossier->id }}">
                <x-tableau.cellule>
                    <a href="{{ route('dossiers.fiche', $dossier) }}" wire:navigate class="chiffres font-medium text-marine hover:underline">{{ $dossier->numero_dossier }}</a>
                </x-tableau.cellule>
                <x-tableau.cellule>
                    <p class="font-medium text-encre">{{ $dossier->stagiaire->nom }} {{ $dossier->stagiaire->prenom }}</p>
                    <p class="chiffres text-xs text-discret">
                        CEF {{ $dossier->stagiaire->cef }}
                        @if ($dossier->stagiaire->statut_inscription !== StatutInscription::Verifiee) · <span class="text-alerte">inscription non vérifiée</span>@endif
                    </p>
                </x-tableau.cellule>
                <x-tableau.cellule class="text-encre-2">{{ $dossier->stagiaire->nom_filiere }}</x-tableau.cellule>
                <x-tableau.cellule class="text-encre-2">{{ $dossier->campagne->internat->nom }}</x-tableau.cellule>
                <x-tableau.cellule><x-statut :etat="$dossier->statut" /></x-tableau.cellule>
                <x-tableau.cellule class="chiffres text-encre-2">{{ Format::dateHeure($dossier->date_soumission) }}</x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>
</div>
