@php
    use App\Enums\TypeDocument;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Documents utiles" description="Règlement intérieur, dates d’arrivée, notes d’information : documents mis à la disposition des stagiaires de l’internat.">
        <x-slot:actions>
            <x-bouton variante="primaire" icone="plus" wire:click="preparer">Publier un document</x-bouton>
        </x-slot:actions>
    </x-page.entete>

    <x-carte>
        <x-carte.entete titre="Documents publiés" />
        @if ($documents->isEmpty())
            <x-vide titre="Aucun document publié" icone="file-text">Publiez le règlement intérieur et les dates d’arrivée pour les rendre accessibles aux stagiaires.</x-vide>
        @else
            <ul>
                @foreach ($documents as $document)
                    <li wire:key="document-{{ $document->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-bord-doux px-5 py-3 last:border-0">
                        <div class="min-w-0 flex-1 basis-64">
                            <p class="text-base font-medium">{{ $document->titre }}</p>
                            <p class="text-sm text-discret">{{ $document->internat->nom }} · publié le {{ Format::date($document->date_publication) }} par {{ $document->auteur?->nomComplet() }}</p>
                        </div>
                        <x-badge ton="marine">{{ $document->type->libelle() }}</x-badge>
                        <x-bouton taille="sm" icone="eye" wire:click="consulter({{ $document->id }})">Consulter</x-bouton>
                        <x-bouton variante="discret" taille="icone" icone="trash" aria-label="Retirer {{ $document->titre }}"
                            x-on:click="$confirmer({ titre: 'Retirer ce document ?', texte: {{ Js::from('« '.$document->titre.' » ne sera plus accessible aux stagiaires.') }}, libelle: 'Retirer', destructif: true }).then(() => $wire.retirer({{ $document->id }}))" />
                    </li>
                @endforeach
            </ul>
        @endif
    </x-carte>

    @if ($dialogue === 'publication')
        <x-dialogue titre="Publier un document" description="Le document devient lisible par les stagiaires de l’internat, et par eux seuls.">
            <form wire:submit="publier" id="form-document" novalidate class="space-y-4">
                @if ($internats->count() > 1)
                    <x-liste libelle="Internat concerné" wire:model="saisie.internat_id" :options="$internats" requis />
                @endif
                <x-saisie libelle="Titre affiché au stagiaire" wire:model="saisie.titre" maxlength="190" requis autofocus />
                <x-liste libelle="Nature" wire:model="saisie.type" :options="TypeDocument::options()" requis />
                <div>
                    <p class="mb-1.5 text-sm font-medium text-encre">Fichier <span class="text-danger" aria-hidden="true">*</span></p>
                    <x-fichier wire:model="fichier" :fichier="$fichier" accept=".pdf" />
                </div>
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-document" variante="primaire" cible="publier,fichier">Publier</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'apercu' && $apercu)
        <x-apercu :chemin="route('documents.fichier', $apercu)" :titre="$apercu->titre" :nom="$apercu->nom_original" />
    @endif
</div>
