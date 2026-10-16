@php
    use App\Enums\CategorieCritere;
    use App\Enums\DeclencheurCritere;
    use App\Enums\NiveauFormation;

    $niveaux = collect(NiveauFormation::options())->pluck('libelle', 'valeur');
@endphp
<div>
    <x-page.entete titre="Grille de référence"
        description="Critères types proposés à tous les établissements. Ils sont recopiés dans chaque nouvelle campagne, où l’administrateur peut les adapter avant l’ouverture.">
        <x-slot:actions><x-bouton variante="primaire" icone="plus" wire:click="preparer">Ajouter un critère</x-bouton></x-slot:actions>
    </x-page.entete>

    @if ($maximum > $plafond)
        <x-alerte ton="danger" class="mb-4" titre="Barème dépassé">
            Les critères actifs permettent d’atteindre {{ $maximum }} points alors que le score est plafonné à {{ $plafond }}.
        </x-alerte>
    @endif

    <x-tableau legende="Grille nationale de critères de référence" :lignes="$criteres" :plafonne="false" vide="Aucun critère de référence">
        <x-slot:barre>
            <p class="text-sm text-discret">Score maximal atteignable avec les critères actifs : <span class="chiffres font-medium text-encre">{{ $maximum }} / {{ $plafond }}</span> points.</p>
        </x-slot:barre>
        <x-slot:entete>
            <x-tableau.colonne>Catégorie</x-tableau.colonne>
            <x-tableau.colonne>Condition</x-tableau.colonne>
            <x-tableau.colonne>Donnée du dossier</x-tableau.colonne>
            <x-tableau.colonne>Pièce justificative</x-tableau.colonne>
            <x-tableau.colonne droite>Points</x-tableau.colonne>
            <x-tableau.colonne>État</x-tableau.colonne>
            <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
        </x-slot:entete>
        @foreach ($criteres as $ligneCritere)
            <x-tableau.ligne wire:key="reference-{{ $ligneCritere->id }}" :class="$ligneCritere->actif ? null : 'opacity-60'">
                <x-tableau.cellule><x-badge>{{ $ligneCritere->categorie->libelle() }}</x-badge></x-tableau.cellule>
                <x-tableau.cellule class="font-medium">{{ $ligneCritere->condition_texte }}</x-tableau.cellule>
                <x-tableau.cellule class="text-encre-2">
                    {{ $ligneCritere->declencheur->libelle() }}@if ($ligneCritere->valeur_attendue) : {{ $niveaux[$ligneCritere->valeur_attendue] ?? $ligneCritere->valeur_attendue }}@endif
                </x-tableau.cellule>
                <x-tableau.cellule class="text-encre-2">{{ $ligneCritere->piece_libelle ?? '—' }}</x-tableau.cellule>
                <x-tableau.cellule droite class="font-medium">{{ $ligneCritere->points }}</x-tableau.cellule>
                <x-tableau.cellule><x-badge :ton="$ligneCritere->actif ? 'succes' : 'neutre'">{{ $ligneCritere->actif ? 'Actif' : 'Inactif' }}</x-badge></x-tableau.cellule>
                <x-tableau.cellule droite>
                    <x-bouton taille="icone" variante="discret" aria-label="Modifier le critère" wire:click="preparer({{ $ligneCritere->id }})"><x-icone nom="pencil" /></x-bouton>
                    <x-bouton taille="icone" variante="discret" aria-label="Supprimer le critère"
                        x-on:click="$confirmer({ titre: 'Supprimer ce critère de référence ?', texte: 'Il ne sera plus proposé aux nouvelles campagnes. Les campagnes existantes ne sont pas modifiées.', libelle: 'Supprimer', destructif: true }).then(() => $wire.supprimer({{ $ligneCritere->id }}))">
                        <x-icone nom="trash" />
                    </x-bouton>
                </x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>

    @if ($dialogue === 'critere')
        <x-dialogue :titre="$ligne ? 'Modifier le critère de référence' : 'Ajouter un critère de référence'">
            <form wire:submit="enregistrer" id="form-reference" novalidate class="grid gap-4 sm:grid-cols-2">
                <x-liste libelle="Catégorie" wire:model="critere.categorie" :options="CategorieCritere::options()" vide="Choisir…" requis />
                <x-liste libelle="Donnée du dossier" wire:model.live="critere.declencheur" :options="DeclencheurCritere::options()" vide="Choisir…" requis />
                @if (($critere['declencheur'] ?? '') === DeclencheurCritere::NiveauFormation->value)
                    <x-liste libelle="Niveau de formation visé" wire:model="critere.valeur_attendue" :options="NiveauFormation::options()" vide="Choisir…" requis class="sm:col-span-2" />
                @endif
                <x-saisie libelle="Condition" wire:model="critere.condition" requis class="sm:col-span-2" />
                <x-saisie libelle="Points" wire:model="critere.points" type="number" min="0" max="80" requis />
                <x-saisie libelle="Pièce justificative" wire:model="critere.piece_libelle" aide="Libellé de la pièce à créer dans la campagne, s’il en faut une." />
                <div class="sm:col-span-2"><x-case wire:model="critere.actif" libelle="Critère actif : proposé aux nouvelles campagnes" /></div>
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-reference" variante="primaire" cible="enregistrer">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
