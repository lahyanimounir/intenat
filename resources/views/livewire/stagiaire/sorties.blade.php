@php use App\Support\Affichage\Format; @endphp
<div>
    <x-page.entete titre="Sorties" description="Demandez l’autorisation de vous absenter de l’internat. Le gestionnaire accepte ou refuse chaque demande.">
        @if ($resident)
            <x-slot:actions><x-bouton variante="primaire" icone="plus" wire:click="ouvrirDialogue('sortie')">Nouvelle demande</x-bouton></x-slot:actions>
        @endif
    </x-page.entete>

    @if (! $resident && $sorties->isEmpty())
        <x-carte><x-vide titre="Réservé aux résidents" icone="door-open">Les demandes de sortie concernent les stagiaires hébergés à l’internat.</x-vide></x-carte>
    @else
        <x-tableau legende="Mes demandes de sortie" :lignes="$sorties" vide="Aucune demande de sortie" :plafonne="false">
            <x-slot:entete>
                <x-tableau.colonne>Départ</x-tableau.colonne>
                <x-tableau.colonne>Retour</x-tableau.colonne>
                <x-tableau.colonne>Motif</x-tableau.colonne>
                <x-tableau.colonne>Décision</x-tableau.colonne>
            </x-slot:entete>
            @foreach ($sorties as $sortie)
                <x-tableau.ligne wire:key="sortie-{{ $sortie->id }}">
                    <x-tableau.cellule class="chiffres whitespace-nowrap">{{ Format::dateHeure($sortie->date_depart) }}</x-tableau.cellule>
                    <x-tableau.cellule class="chiffres whitespace-nowrap">{{ Format::dateHeure($sortie->date_retour) }}</x-tableau.cellule>
                    <x-tableau.cellule>{{ $sortie->motif }}</x-tableau.cellule>
                    <x-tableau.cellule>
                        <x-statut :etat="$sortie->statut" />
                        @if ($sortie->motif_refus)<p class="mt-1 text-sm text-discret">{{ $sortie->motif_refus }}</p>@endif
                    </x-tableau.cellule>
                </x-tableau.ligne>
            @endforeach
        </x-tableau>
    @endif

    @if ($dialogue === 'sortie')
        <x-dialogue titre="Demander une sortie" description="Indiquez la période et le motif de votre absence.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-saisie libelle="Départ" type="datetime-local" wire:model="date_depart" requis autofocus />
                <x-saisie libelle="Retour" type="datetime-local" wire:model="date_retour" requis />
                <x-zone-texte libelle="Motif" wire:model="motif" requis class="sm:col-span-2" />
            </div>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton variante="primaire" wire:click="deposer" cible="deposer">Envoyer la demande</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
