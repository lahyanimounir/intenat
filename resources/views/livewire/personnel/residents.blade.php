@php
    use App\Enums\StatutDemande;
    use App\Enums\TypeMouvement;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Résidents" description="Stagiaires hébergés à l’internat. Ouvrez une fiche pour enregistrer une arrivée, un changement de lit, une remise d’équipement ou une fin de séjour." />

    @if ($arrivee === 'attendue')
        <x-alerte ton="info" class="mb-4">
            Lits attribués dont l’occupant n’est pas encore arrivé.
            <x-slot:action><x-bouton taille="sm" wire:click="$set('arrivee', '')">Voir tous les résidents</x-bouton></x-slot:action>
        </x-alerte>
    @endif

    <x-tableau legende="Résidents de l’internat" :lignes="$residents" :vide="$etat === 'clos' ? 'Aucun séjour clos' : 'Aucun résident'"
        vide-description="Un résident apparaît ici dès qu’un lit lui est attribué.">
        <x-slot:barre>
            <x-recherche placeholder="Nom, CEF ou n° de dossier" libelle="Rechercher un résident" />
            <x-filtre libelle="Séjours" wire:model.live="etat" :options="['clos' => 'Séjours clos']" tous="en cours" :actif="$etat === 'clos'" />
            @if ($internats->count() > 1)
                <x-filtre libelle="Internat" wire:model.live="internat" :options="$internats" :actif="$internat !== ''" />
            @endif
        </x-slot:barre>
        <x-slot:entete>
            <x-tableau.colonne>Résident</x-tableau.colonne>
            <x-tableau.colonne>Internat</x-tableau.colonne>
            <x-tableau.colonne>Bâtiment</x-tableau.colonne>
            <x-tableau.colonne>Chambre et lit</x-tableau.colonne>
            <x-tableau.colonne>Depuis le</x-tableau.colonne>
            <x-tableau.colonne>Arrivée</x-tableau.colonne>
        </x-slot:entete>

        @foreach ($residents as $resident)
            @php $arriveeLe = $resident->mouvements->first(fn ($m) => $m->type === TypeMouvement::Arrivee)?->date_mouvement; @endphp
            <x-tableau.ligne :href="route('hebergement.residents.fiche', $resident)" wire:key="resident-{{ $resident->id }}">
                <x-tableau.cellule>
                    <a href="{{ route('hebergement.residents.fiche', $resident) }}" wire:navigate class="font-medium text-marine hover:underline">
                        {{ $resident->demande->stagiaire->nom }} {{ $resident->demande->stagiaire->prenom }}
                    </a>
                    <p class="chiffres text-xs text-discret">CEF {{ $resident->demande->stagiaire->cef }} · {{ $resident->demande->numero_dossier }}</p>
                </x-tableau.cellule>
                <x-tableau.cellule class="text-encre-2">{{ $resident->demande->campagne->internat->nom }}</x-tableau.cellule>
                <x-tableau.cellule class="text-encre-2">{{ $resident->lit->chambre->batiment->code }} — {{ $resident->lit->chambre->batiment->nom }}</x-tableau.cellule>
                <x-tableau.cellule class="chiffres">Chambre {{ $resident->lit->chambre->numero }}, lit {{ $resident->lit->numero }}</x-tableau.cellule>
                <x-tableau.cellule class="chiffres text-encre-2">
                    {{ Format::date($resident->date_debut) }}@if ($resident->date_fin) → {{ Format::date($resident->date_fin) }}@endif
                </x-tableau.cellule>
                <x-tableau.cellule>
                    @if ($resident->date_fin)
                        <x-badge>Séjour clos</x-badge>
                    @elseif ($resident->demande->statut === StatutDemande::Desiste)
                        <x-badge ton="danger">Désisté</x-badge>
                    @elseif ($arriveeLe)
                        <x-badge ton="succes" point>Arrivé le {{ Format::date($arriveeLe) }}</x-badge>
                    @else
                        <x-badge ton="alerte" point>Arrivée attendue</x-badge>
                    @endif
                </x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>
</div>
