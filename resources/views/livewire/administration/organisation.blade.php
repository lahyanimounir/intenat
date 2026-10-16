@php
    use App\Enums\Genre;

    // Question posée avant toute suppression : la base refuse de toute façon un élément encore utilisé.
    $suppression = fn (string $type, int $id, string $libelle) => "\$confirmer({ titre: 'Supprimer cet élément ?', texte: ".Js::from('« '.$libelle.' » ne peut être supprimé que si aucune donnée ne lui est rattachée.').", libelle: 'Supprimer', destructif: true }).then(() => \$wire.supprimer('".$type."', ".$id.'))';
@endphp
<div>
    <x-page.entete titre="Organisation"
        description="Direction centrale → directions régionales → complexes / CMC → établissements (EFP) → internats. La liste des établissements est aussi le référentiel dans lequel chaque stagiaire choisit son établissement à l’inscription.">
        <x-slot:actions>
            <x-bouton icone="plus" wire:click="ajouter('regions')">Région</x-bouton>
            <x-bouton icone="plus" wire:click="ajouter('complexes')">Complexe</x-bouton>
            <x-bouton icone="plus" wire:click="ajouter('etablissements')">Établissement</x-bouton>
            <x-bouton variante="primaire" icone="plus" wire:click="ajouter('internats')">Internat</x-bouton>
        </x-slot:actions>
    </x-page.entete>

    @if ($regions->isEmpty())
        <x-carte><x-vide titre="Aucune région" icone="network">Commencez par créer une direction régionale.</x-vide></x-carte>
    @else
        <div class="space-y-6">
            @foreach ($regions as $region)
                @php
                    $desComplexes = $complexes->get($region->id, collect());
                    $desEtablissements = $etablissements->get($region->id, collect());
                @endphp
                <x-carte wire:key="region-{{ $region->id }}">
                    <x-carte.entete :titre="$region->nom" :description="'Code '.$region->code.' · '.$desComplexes->count().' complexe(s) · '.$desEtablissements->count().' établissement(s)'">
                        <x-slot:actions>
                            <span class="flex shrink-0 gap-0.5">
                                <x-bouton variante="discret" taille="icone" icone="pencil" aria-label="Modifier {{ $region->nom }}" wire:click="modifier('regions', {{ $region->id }})" />
                                <x-bouton variante="discret" taille="icone" icone="trash" aria-label="Supprimer {{ $region->nom }}" x-on:click="{{ $suppression('regions', $region->id, $region->nom) }}" />
                            </span>
                        </x-slot:actions>
                    </x-carte.entete>

                    @if ($desComplexes->isNotEmpty())
                        <ul class="flex flex-wrap gap-2 border-b border-bord-doux px-5 py-3">
                            @foreach ($desComplexes as $complexe)
                                <li wire:key="complexe-{{ $complexe->id }}" class="flex items-center gap-1 rounded-md border border-bord bg-surface-2 py-0.5 pr-1 pl-2.5 text-sm">
                                    <span><span class="font-medium">{{ $complexe->nom }}</span> <span class="text-discret">· {{ $complexe->code }}</span></span>
                                    <span class="flex shrink-0 gap-0.5">
                                        <x-bouton variante="discret" taille="icone" icone="pencil" aria-label="Modifier {{ $complexe->nom }}" wire:click="modifier('complexes', {{ $complexe->id }})" />
                                        <x-bouton variante="discret" taille="icone" icone="trash" aria-label="Supprimer {{ $complexe->nom }}" x-on:click="{{ $suppression('complexes', $complexe->id, $complexe->nom) }}" />
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($desEtablissements->isEmpty())
                        <x-vide titre="Aucun établissement dans cette région" class="py-8" />
                    @else
                        <ul>
                            @foreach ($desEtablissements as $etablissement)
                                @php $desInternats = $internats->get($etablissement->id, collect()); @endphp
                                <li wire:key="etablissement-{{ $etablissement->id }}" class="border-b border-bord-doux px-5 py-3.5 last:border-0">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-base font-medium">{{ $etablissement->nom_efp }} <code class="ml-1 rounded-sm bg-neutre-fond px-1.5 py-0.5 text-xs font-normal">{{ $etablissement->code_efp }}</code></p>
                                            <p class="text-sm text-discret">{{ $nomsComplexes[$etablissement->complexe_id] ?? 'Sans complexe' }}</p>
                                        </div>
                                        <span class="flex shrink-0 gap-0.5">
                                            <x-bouton variante="discret" taille="icone" icone="pencil" aria-label="Modifier {{ $etablissement->nom_efp }}" wire:click="modifier('etablissements', {{ $etablissement->id }})" />
                                            <x-bouton variante="discret" taille="icone" icone="trash" aria-label="Supprimer {{ $etablissement->nom_efp }}" x-on:click="{{ $suppression('etablissements', $etablissement->id, $etablissement->nom_efp) }}" />
                                        </span>
                                    </div>
                                    <ul class="mt-2.5 flex flex-wrap gap-2">
                                        @forelse ($desInternats as $internat)
                                            <li wire:key="internat-{{ $internat->id }}" class="flex items-center gap-1 rounded-md border border-bord py-0.5 pr-1 pl-2.5 text-sm">
                                                <x-badge ton="marine">{{ $internat->genre->libelle() }}</x-badge>
                                                <span class="ml-1.5">{{ $internat->nom }}</span>
                                                <span class="flex shrink-0 gap-0.5">
                                                    <x-bouton variante="discret" taille="icone" icone="pencil" aria-label="Modifier {{ $internat->nom }}" wire:click="modifier('internats', {{ $internat->id }})" />
                                                    <x-bouton variante="discret" taille="icone" icone="trash" aria-label="Supprimer {{ $internat->nom }}" x-on:click="{{ $suppression('internats', $internat->id, $internat->nom) }}" />
                                                </span>
                                            </li>
                                        @empty
                                            <li class="text-sm text-discret">Aucun internat rattaché.</li>
                                        @endforelse
                                    </ul>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-carte>
            @endforeach
        </div>
    @endif

    @if ($dialogue === 'element')
        <x-dialogue :titre="$titre">
            <form wire:submit="enregistrer" id="form-element" novalidate class="space-y-4">
                @if (in_array($niveau, ['complexes', 'etablissements'], true))
                    <x-liste libelle="Région" wire:model.live="saisie.region_id" :options="$choixRegions" vide="Choisir une région…" requis />
                @endif

                @if ($niveau === 'etablissements')
                    <x-liste libelle="Complexe / CMC" wire:model="saisie.complexe_id" :options="$choixComplexes" vide="Aucun" aide="Facultatif. Le complexe doit appartenir à la région choisie." wire:key="complexes-de-{{ $saisie['region_id'] ?? '' }}" />
                    <x-saisie libelle="Code EFP" wire:model="saisie.code_efp" placeholder="LC10" maxlength="20" requis />
                    <x-saisie libelle="Nom EFP" wire:model="saisie.nom_efp" maxlength="190" requis aide="Nom officiel de l’établissement : c’est celui que les stagiaires voient dans la liste à l’inscription." />
                @endif

                @if ($niveau === 'internats')
                    <x-liste libelle="Établissement" wire:model="saisie.etablissement_id" :options="$choixEtablissements" vide="Choisir un établissement…" requis />
                    <x-liste libelle="Genre accueilli" wire:model="saisie.genre" :options="[Genre::Fille->value => 'Filles', Genre::Garcon->value => 'Garçons']" requis
                        aide="Un internat n’accueille qu’un seul genre ; un établissement a au plus un internat par genre." />
                @endif

                @if (in_array($niveau, ['regions', 'complexes'], true))
                    <x-saisie libelle="Code" wire:model="saisie.code" maxlength="20" requis />
                @endif

                @if ($niveau !== 'etablissements')
                    <x-saisie libelle="Nom" wire:model="saisie.nom" maxlength="150" requis />
                @endif
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-element" variante="primaire" cible="enregistrer">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
