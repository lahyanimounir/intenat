@php use App\Support\Affichage\Format; @endphp
<div>
    <x-page.entete titre="Campagnes" description="Une ou plusieurs campagnes par internat et par année de formation, sur des périodes de dépôt distinctes : dates de dépôt, places offertes, pièces exigées et grille de notation.">
        @if ($gerer)
            <x-slot:actions><x-bouton variante="primaire" icone="plus" wire:click="preparer">Nouvelle campagne</x-bouton></x-slot:actions>
        @endif
    </x-page.entete>

    <x-tableau legende="Campagnes d’admission" :lignes="$campagnes" vide="Aucune campagne"
        :vide-description="$gerer ? 'Créez la première campagne de l’année pour ouvrir le dépôt des demandes.' : 'Les campagnes sont créées par le chef de pôle scolarité.'">
        <x-slot:barre>
            <x-filtre libelle="Statut" wire:model.live="statut" :options="$statuts" :actif="$statut !== ''" />
            @if ($plusieursInternats)
                {{-- Hiérarchie administrative jusqu'à l'internat ; sans niveau choisi, tous les internats restent proposés. --}}
                <x-filtres-hierarchiques :filtre="$filtre" :region="$region" :province="$province" :complexe="$complexe" :etablissement="$etablissement" />
                <x-filtre libelle="Internat" wire:model.live="internat" :options="$internats" :actif="$internat !== ''" wire:key="filtre-internat-{{ $etablissement }}-{{ $region }}" />
            @endif
            <x-case wire:model.live="archives" libelle="Afficher les campagnes archivées" />
        </x-slot:barre>

        <x-slot:entete>
            <x-tableau.colonne>Année</x-tableau.colonne>
            <x-tableau.colonne>Internat</x-tableau.colonne>
            <x-tableau.colonne>Dépôt des dossiers</x-tableau.colonne>
            <x-tableau.colonne droite>Places</x-tableau.colonne>
            <x-tableau.colonne droite>Dossiers</x-tableau.colonne>
            <x-tableau.colonne>Statut</x-tableau.colonne>
        </x-slot:entete>

        @foreach ($campagnes as $campagne)
            <x-tableau.ligne :href="route('campagnes.fiche', $campagne)" wire:key="campagne-{{ $campagne->id }}">
                <x-tableau.cellule>
                    <a href="{{ route('campagnes.fiche', $campagne) }}" wire:navigate class="chiffres font-medium text-marine hover:underline">{{ $campagne->annee }}</a>
                </x-tableau.cellule>
                <x-tableau.cellule>
                    <p class="font-medium text-encre">{{ $campagne->internat->nom }}</p>
                    <p class="text-xs text-discret">{{ $campagne->internat->etablissement->nom_efp }}</p>
                </x-tableau.cellule>
                <x-tableau.cellule class="chiffres text-encre-2">du {{ Format::date($campagne->date_ouverture) }} au {{ Format::date($campagne->date_cloture) }}</x-tableau.cellule>
                <x-tableau.cellule droite>{{ Format::nombre($campagne->nombre_places) }}</x-tableau.cellule>
                <x-tableau.cellule droite>{{ Format::nombre($campagne->demandes_count) }}</x-tableau.cellule>
                <x-tableau.cellule>
                    <x-statut :etat="$campagne->statut" />
                    @if ($campagne->estArchivee())<x-badge class="ml-1">Archivée</x-badge>@elseif ($campagne->estDeliberee())<x-badge ton="marine" class="ml-1">Délibérée</x-badge>@endif
                </x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>

    @if ($dialogue === 'creation')
        <x-dialogue titre="Nouvelle campagne" description="Une campagne est créée pour chaque internat dont vous indiquez le nombre de places. La grille nationale de référence y est recopiée." largeur="lg">
            <form wire:submit="creer" id="creation-campagne" novalidate class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-saisie libelle="Année de formation" wire:model="nouvelle.annee" placeholder="2026-2027" requis autofocus />
                    <x-saisie libelle="Frais d’internat par mois (DH)" wire:model="nouvelle.frais" type="number" min="0" step="0.01" inputmode="decimal"
                        aide="Montant de l’hébergement, repris dans les autorisations de paiement." />
                    <x-saisie libelle="Ouverture du dépôt" wire:model="nouvelle.date_ouverture" type="date" requis />
                    <x-saisie libelle="Clôture du dépôt" wire:model="nouvelle.date_cloture" type="date" requis />
                </div>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-encre">Places offertes par internat</legend>
                    <div class="divide-y divide-bord-doux rounded-md border border-bord">
                        @foreach ($internatsCreation as $ligne)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-3.5 py-2.5" wire:key="places-{{ $ligne->id }}">
                                <label for="places-{{ $ligne->id }}" class="min-w-0">
                                    <span class="block text-base font-medium text-encre">{{ $ligne->nom }}</span>
                                    <span class="block text-sm text-discret">{{ Format::nombre($ligne->lits) }} lit(s) éligible(s) dans le référentiel</span>
                                </label>
                                <div class="w-28">
                                    <input id="places-{{ $ligne->id }}" type="number" min="1" max="{{ max(1, $ligne->lits) }}" inputmode="numeric" placeholder="Places"
                                        wire:model="places.{{ $ligne->id }}" @error('places.'.$ligne->id) aria-invalid="true" @enderror class="champ h-9 text-right" />
                                </div>
                                @error('places.'.$ligne->id)<p role="alert" class="w-full text-sm text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                    @error('places')<p role="alert" class="mt-1.5 text-sm text-danger">{{ $message }}</p>@enderror
                </fieldset>
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="creation-campagne" variante="primaire" cible="creer">Créer la campagne</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
