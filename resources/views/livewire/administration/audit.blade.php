@php
    use App\Support\Affichage\Format;

    $auteur = fn ($entree) => $entree->utilisateur?->nomComplet() ?? ($entree->stagiaire ? trim($entree->stagiaire->prenom.' '.$entree->stagiaire->nom).' (stagiaire)' : 'Système');
    $champDate = 'h-8 rounded-md border border-bord bg-surface px-2 text-sm text-encre shadow-xs';
@endphp
<div>
    <x-page.entete titre="Journal d’audit"
        description="Qui a fait quoi, quand, et sur quelle ligne. Le journal est en ajout seul : ni l’application ni la base n’acceptent de le modifier ou d’y supprimer une entrée. Aucun mot de passe ni contenu de document n’y figure." />

    <x-tableau legende="Journal d’audit" :lignes="$entrees" vide="Aucune entrée ne correspond aux filtres">
        <x-slot:barre>
            <x-filtre libelle="Famille d’actions" wire:model.live="action" :options="$familles" tous="toutes" :actif="$action !== ''" />
            <x-filtre libelle="Table" wire:model.live="entite" :options="$entites" tous="toutes" :actif="$entite !== ''" />
            <label class="flex items-center gap-1.5 text-sm text-discret">Du <input type="date" wire:model.live="du" class="{{ $champDate }}" /></label>
            <label class="flex items-center gap-1.5 text-sm text-discret">au <input type="date" wire:model.live="au" class="{{ $champDate }}" /></label>
            @if ($filtresActifs)
                <x-bouton taille="sm" variante="lien" wire:click="effacerFiltres">Effacer les filtres</x-bouton>
            @endif
        </x-slot:barre>

        <x-slot:entete>
            <x-tableau.colonne>Date et heure</x-tableau.colonne>
            <x-tableau.colonne>Auteur</x-tableau.colonne>
            <x-tableau.colonne>Action</x-tableau.colonne>
            <x-tableau.colonne>Ligne concernée</x-tableau.colonne>
            <x-tableau.colonne>Détails</x-tableau.colonne>
        </x-slot:entete>

        @foreach ($entrees as $entree)
            <x-tableau.ligne wire:key="entree-{{ $entree->id }}">
                <x-tableau.cellule dense class="chiffres whitespace-nowrap">{{ Format::dateHeure($entree->date_heure) }}</x-tableau.cellule>
                <x-tableau.cellule dense class="font-medium">{{ $auteur($entree) }}</x-tableau.cellule>
                <x-tableau.cellule dense><code class="text-xs">{{ $entree->action }}</code></x-tableau.cellule>
                <x-tableau.cellule dense class="text-encre-2">{{ $entree->entite }}@if ($entree->entite_id)<span class="chiffres"> #{{ $entree->entite_id }}</span>@endif</x-tableau.cellule>
                <x-tableau.cellule dense>
                    @if ($entree->details)
                        <x-bouton taille="sm" variante="discret" wire:click="detailler({{ $entree->id }})">Voir</x-bouton>
                    @else
                        <span class="text-discret">—</span>
                    @endif
                </x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>

    @if ($dialogue === 'detail' && $detail)
        <x-dialogue :titre="$detail->action" :description="$auteur($detail).' · '.Format::dateHeure($detail->date_heure)" largeur="lg">
            <div class="space-y-4">
                <x-definitions :colonnes="3">
                    <x-definition terme="Table">{{ $detail->entite }}</x-definition>
                    <x-definition terme="Identifiant">{{ $detail->entite_id }}</x-definition>
                    <x-definition terme="Adresse IP">{{ $detail->adresse_ip }}</x-definition>
                </x-definitions>
                <pre class="defilement-fin max-h-96 overflow-auto rounded-md border border-bord bg-surface-2 p-3.5 text-xs leading-relaxed text-encre">{{ json_encode($detail->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Fermer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
