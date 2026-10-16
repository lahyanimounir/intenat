@php use App\Support\Affichage\Format; @endphp
<div>
    <x-page.entete titre="Documents utiles" :description="$internat ? 'Publiés par '.$internat->nom.'.' : 'Règlement intérieur, notes et formulaires de votre internat.'" />

    <x-tableau legende="Documents publiés par l’internat" :lignes="$documents" vide="Aucun document publié" :plafonne="false"
        vide-description="Les documents mis à disposition par votre internat apparaîtront ici.">
        <x-slot:entete>
            <x-tableau.colonne>Document</x-tableau.colonne>
            <x-tableau.colonne>Type</x-tableau.colonne>
            <x-tableau.colonne>Publié le</x-tableau.colonne>
            <x-tableau.colonne><span class="sr-only">Télécharger</span></x-tableau.colonne>
        </x-slot:entete>
        @foreach ($documents as $document)
            <x-tableau.ligne wire:key="document-{{ $document->id }}">
                <x-tableau.cellule class="font-medium">{{ $document->titre }}</x-tableau.cellule>
                <x-tableau.cellule>{{ $document->type->libelle() }}</x-tableau.cellule>
                <x-tableau.cellule class="chiffres">{{ Format::date($document->date_publication) }}</x-tableau.cellule>
                <x-tableau.cellule droite>
                    <x-bouton taille="sm" variante="discret" icone="download" :href="route('stagiaire.documents.fichier', $document)" :navigation="false" target="_blank" rel="noopener">Ouvrir</x-bouton>
                </x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>
</div>
