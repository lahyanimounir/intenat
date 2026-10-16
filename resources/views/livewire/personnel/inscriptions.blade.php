@php use App\Support\Affichage\Format; @endphp
<div>
    <x-page.entete titre="Inscriptions à vérifier"
        description="Les stagiaires déclarent eux-mêmes leur inscription en formation. Elle n’ouvre la sélection qu’une fois vérifiée ici, sur justificatif ou sur le registre de scolarité. Cette vérification porte sur les frais de scolarité, pas sur le paiement de l’hébergement." />

    <x-onglets libelle="Vues">
        <x-onglet :actif="$vue === 'attente'" wire:click="voir('attente')" :compteur="$compteurs['attente']">En attente</x-onglet>
        <x-onglet :actif="$vue === 'reprises'" wire:click="voir('reprises')" :compteur="$compteurs['reprises']">Reprises à réexaminer</x-onglet>
        <x-onglet :actif="$vue === 'stagiaires'" wire:click="voir('stagiaires')">Tous les stagiaires</x-onglet>
    </x-onglets>

    <div class="pt-5" wire:key="vue-{{ $vue }}">
        @if ($vue === 'reprises')
            <x-alerte ton="info" class="mb-4" titre="Inscriptions établies autrefois par MyWay">
                Ces comptes ont été créés lors de l’ancienne première connexion par MyWay. Leur inscription est restée « vérifiée » à l’abandon de MyWay,
                sans qu’un agent l’ait contrôlée : confirmez-la, ou remettez-la en cause, depuis la fiche du stagiaire.
            </x-alerte>
        @endif

        <x-tableau :lignes="$lignes" :tri="$tri" :sens="$sens"
            :legende="match ($vue) { 'attente' => 'Demandes de vérification en attente', 'reprises' => 'Inscriptions reprises à réexaminer', default => 'Stagiaires et état de leur inscription' }"
            :vide="match ($vue) { 'attente' => 'Aucune demande en attente', 'reprises' => 'Aucune reprise à réexaminer', default => 'Aucun stagiaire' }"
            :vide-description="$vue === 'attente' ? 'Les nouvelles demandes apparaîtront ici dès qu’un stagiaire de votre périmètre soumettra son inscription.' : null">
            <x-slot:barre>
                <x-recherche placeholder="Nom, CEF, adresse e-mail…" libelle="Rechercher un stagiaire" />
                @if ($etablissements->count() > 1)
                    <x-filtre libelle="Établissement" wire:model.live="etablissement" :options="$etablissements" :actif="$etablissement !== ''" />
                @endif
                @if ($vue === 'stagiaires')
                    <x-filtre libelle="Inscription" wire:model.live="statut" :options="$statuts" tous="toutes" :actif="$statut !== ''" />
                @endif
            </x-slot:barre>

            <x-slot:entete>
                @if ($vue === 'stagiaires')
                    <x-tableau.colonne cle="nom">Stagiaire</x-tableau.colonne>
                    <x-tableau.colonne cle="cef">CEF</x-tableau.colonne>
                    <x-tableau.colonne>Établissement</x-tableau.colonne>
                    <x-tableau.colonne>Filière</x-tableau.colonne>
                    <x-tableau.colonne cle="statut">Inscription</x-tableau.colonne>
                    <x-tableau.colonne cle="verifiee">Vérifiée le</x-tableau.colonne>
                @else
                    <x-tableau.colonne>{{ $vue === 'attente' ? 'Soumise le' : 'Reprise le' }}</x-tableau.colonne>
                    <x-tableau.colonne>Stagiaire</x-tableau.colonne>
                    <x-tableau.colonne>CEF</x-tableau.colonne>
                    <x-tableau.colonne>Établissement</x-tableau.colonne>
                    <x-tableau.colonne>Filière</x-tableau.colonne>
                    <x-tableau.colonne>{{ $vue === 'attente' ? 'Justificatif' : 'État' }}</x-tableau.colonne>
                @endif
                <x-tableau.colonne><span class="sr-only">Ouvrir</span></x-tableau.colonne>
            </x-slot:entete>

            @foreach ($lignes as $ligne)
                @if ($vue === 'stagiaires')
                    <x-tableau.ligne :href="route('inscriptions.fiche', $ligne)" wire:key="stagiaire-{{ $ligne->id }}">
                        <x-tableau.cellule>
                            <span class="font-medium">{{ $ligne->nomComplet() }}</span>
                            <span class="block text-xs text-discret">{{ $ligne->genre?->libelle() }}</span>
                        </x-tableau.cellule>
                        <x-tableau.cellule class="chiffres">{{ $ligne->cef }}</x-tableau.cellule>
                        <x-tableau.cellule>{{ $ligne->etablissement?->nom_efp }}</x-tableau.cellule>
                        <x-tableau.cellule>{{ $ligne->nom_filiere }}</x-tableau.cellule>
                        <x-tableau.cellule><x-statut :etat="$ligne->statut_inscription" /></x-tableau.cellule>
                        <x-tableau.cellule class="chiffres">{{ Format::date($ligne->inscription_verifiee_le) }}</x-tableau.cellule>
                        <x-tableau.cellule droite>
                            <x-bouton variante="discret" taille="sm" :href="route('inscriptions.fiche', $ligne)" icone-fin="chevron-right">Ouvrir</x-bouton>
                        </x-tableau.cellule>
                    </x-tableau.ligne>
                @else
                    <x-tableau.ligne :href="route('inscriptions.fiche', $ligne->stagiaire_id)" wire:key="verification-{{ $ligne->id }}">
                        <x-tableau.cellule class="chiffres whitespace-nowrap">{{ Format::dateHeure($ligne->soumise_le) }}</x-tableau.cellule>
                        <x-tableau.cellule>
                            <span class="font-medium">{{ $ligne->stagiaire->nomComplet() }}</span>
                            <span class="block text-xs text-discret">{{ $ligne->stagiaire->genre?->libelle() }}</span>
                        </x-tableau.cellule>
                        <x-tableau.cellule class="chiffres">{{ $ligne->cef }}</x-tableau.cellule>
                        <x-tableau.cellule>{{ $ligne->etablissement?->nom_efp }}</x-tableau.cellule>
                        <x-tableau.cellule>{{ $ligne->nom_filiere }}</x-tableau.cellule>
                        <x-tableau.cellule>
                            @if ($vue === 'attente')
                                <x-badge :ton="$ligne->aUnJustificatif() ? 'info' : 'alerte'">{{ $ligne->aUnJustificatif() ? 'Déposé' : 'Non fourni' }}</x-badge>
                            @else
                                <x-badge ton="alerte">Sans contrôle d’un agent</x-badge>
                            @endif
                        </x-tableau.cellule>
                        <x-tableau.cellule droite>
                            <x-bouton variante="discret" taille="sm" :href="route('inscriptions.fiche', $ligne->stagiaire_id)" icone-fin="chevron-right">
                                {{ $vue === 'attente' ? 'Vérifier' : 'Réexaminer' }}
                            </x-bouton>
                        </x-tableau.cellule>
                    </x-tableau.ligne>
                @endif
            @endforeach
        </x-tableau>
    </div>
</div>
