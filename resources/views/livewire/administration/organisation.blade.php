@php
    use App\Enums\Genre;

    // Question posée avant toute suppression : la base refuse de toute façon un élément encore utilisé.
    $suppression = fn (string $type, int $id, string $libelle) => "\$confirmer({ titre: 'Supprimer cet élément ?', texte: ".Js::from('« '.$libelle.' » ne peut être supprimé que si aucune donnée ne lui est rattachée.').", libelle: 'Supprimer', destructif: true }).then(() => \$wire.supprimer('".$type."', ".$id.'))';
@endphp
<div>
    <x-page.entete titre="Organisation"
        description="Direction centrale → directions régionales → provinces → complexes / CMC → établissements (EFP) → internats. La hiérarchie est stricte : chaque niveau se rattache au niveau supérieur. La liste des établissements est aussi le référentiel dans lequel chaque stagiaire choisit son établissement à l’inscription.">
        <x-slot:actions>
            {{-- Chaque niveau se crée depuis son parent : seul « Région » (sommet de la hiérarchie) reste ici. --}}
            <x-bouton variante="primaire" icone="plus" wire:click="ajouter('regions')">Région</x-bouton>
        </x-slot:actions>
    </x-page.entete>

    @if ($regions->isEmpty())
        <x-carte><x-vide titre="Aucune région" icone="network">Commencez par créer une direction régionale.</x-vide></x-carte>
    @else
        <div class="space-y-6">
            @foreach ($regions as $region)
                @php $desProvinces = $provinces->get($region->id, collect()); @endphp
                <x-carte wire:key="region-{{ $region->id }}">
                    <x-carte.entete :titre="$region->nom" :description="'Code '.$region->code.' · '.$desProvinces->count().' province(s)'">
                        <x-slot:actions>
                            <span class="flex shrink-0 flex-wrap items-center justify-end gap-1">
                                <x-bouton taille="sm" variante="discret" icone="plus" wire:click="ajouter('provinces', {{ $region->id }})">Province</x-bouton>
                                <x-bouton variante="discret" taille="icone" icone="pencil" aria-label="Modifier {{ $region->nom }}" wire:click="modifier('regions', {{ $region->id }})" />
                                <x-bouton variante="discret" taille="icone" icone="trash" aria-label="Supprimer {{ $region->nom }}" x-on:click="{{ $suppression('regions', $region->id, $region->nom) }}" />
                            </span>
                        </x-slot:actions>
                    </x-carte.entete>

                    @if ($desProvinces->isEmpty())
                        <x-vide titre="Aucune province dans cette région" class="py-8" />
                    @else
                        <div class="divide-y divide-bord-doux">
                            @foreach ($desProvinces as $province)
                                @php $desComplexes = $complexes->get($province->id, collect()); @endphp
                                <section wire:key="province-{{ $province->id }}" class="px-5 py-4">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-base font-semibold">{{ $province->nom }} <span class="text-sm font-normal text-discret">· {{ $province->code }} · {{ $desComplexes->count() }} complexe(s)</span></p>
                                        </div>
                                        <span class="flex shrink-0 flex-wrap items-center justify-end gap-1">
                                            <x-bouton taille="sm" variante="discret" icone="plus" wire:click="ajouter('complexes', {{ $province->id }})">Complexe</x-bouton>
                                            <x-bouton variante="discret" taille="icone" icone="pencil" aria-label="Modifier {{ $province->nom }}" wire:click="modifier('provinces', {{ $province->id }})" />
                                            <x-bouton variante="discret" taille="icone" icone="trash" aria-label="Supprimer {{ $province->nom }}" x-on:click="{{ $suppression('provinces', $province->id, $province->nom) }}" />
                                        </span>
                                    </div>

                                    @if ($desComplexes->isEmpty())
                                        <p class="mt-2 text-sm text-discret">Aucun complexe dans cette province.</p>
                                    @else
                                        <div class="mt-3 space-y-3 border-l-2 border-bord-doux pl-4">
                                            @foreach ($desComplexes as $complexe)
                                                @php $desEtablissements = $etablissements->get($complexe->id, collect()); @endphp
                                                <div wire:key="complexe-{{ $complexe->id }}">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="font-medium">{{ $complexe->nom }}</span>
                                                        <span class="text-sm text-discret">· {{ $complexe->code }} · {{ $desEtablissements->count() }} établissement(s)</span>
                                                        <span class="flex shrink-0 flex-wrap items-center justify-end gap-1">
                                                            <x-bouton taille="sm" variante="discret" icone="plus" wire:click="ajouter('etablissements', {{ $complexe->id }})">Établissement</x-bouton>
                                                            <x-bouton variante="discret" taille="icone" icone="pencil" aria-label="Modifier {{ $complexe->nom }}" wire:click="modifier('complexes', {{ $complexe->id }})" />
                                                            <x-bouton variante="discret" taille="icone" icone="trash" aria-label="Supprimer {{ $complexe->nom }}" x-on:click="{{ $suppression('complexes', $complexe->id, $complexe->nom) }}" />
                                                        </span>
                                                    </div>

                                                    @if ($desEtablissements->isEmpty())
                                                        <p class="mt-1 text-sm text-discret">Aucun établissement dans ce complexe.</p>
                                                    @else
                                                        <ul class="mt-2 space-y-2">
                                                            @foreach ($desEtablissements as $etablissement)
                                                                @php $desInternats = $internats->get($etablissement->id, collect()); @endphp
                                                                <li wire:key="etablissement-{{ $etablissement->id }}" class="rounded-md border border-bord-doux bg-surface-2 px-3 py-2.5">
                                                                    <div class="flex flex-wrap items-center gap-3">
                                                                        <div class="min-w-0 flex-1">
                                                                            <p class="font-medium">{{ $etablissement->nom_efp }} <code class="ml-1 rounded-sm bg-neutre-fond px-1.5 py-0.5 text-xs font-normal">{{ $etablissement->code_efp }}</code></p>
                                                                        </div>
                                                                        <span class="flex shrink-0 flex-wrap items-center justify-end gap-1">
                                                                            <x-bouton taille="sm" variante="discret" icone="plus" wire:click="ajouter('internats', {{ $etablissement->id }})">Internat</x-bouton>
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
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </section>
                            @endforeach
                        </div>
                    @endif
                </x-carte>
            @endforeach
        </div>
    @endif

    @if ($dialogue === 'element')
        <x-dialogue :titre="$titre">
            <form wire:submit="enregistrer" id="form-element" novalidate class="space-y-4">
                @if ($niveau === 'provinces')
                    <x-liste libelle="Région" wire:model="saisie.region_id" :options="$choixRegions" vide="Choisir une région…" requis />
                @endif

                @if ($niveau === 'complexes')
                    <x-liste libelle="Province" wire:model="saisie.province_id" :options="$choixProvinces" vide="Choisir une province…" requis />
                @endif

                @if ($niveau === 'etablissements')
                    <x-liste libelle="Complexe / CMC" wire:model="saisie.complexe_id" :options="$choixComplexes" vide="Choisir un complexe…" requis aide="L’établissement se rattache obligatoirement à un complexe." />
                    <x-saisie libelle="Code EFP" wire:model="saisie.code_efp" placeholder="LC10" maxlength="20" requis />
                    <x-saisie libelle="Nom EFP" wire:model="saisie.nom_efp" maxlength="190" requis aide="Nom officiel de l’établissement : c’est celui que les stagiaires voient dans la liste à l’inscription." />
                @endif

                @if ($niveau === 'internats')
                    <x-liste libelle="Établissement" wire:model="saisie.etablissement_id" :options="$choixEtablissements" vide="Choisir un établissement…" requis />
                    <x-liste libelle="Genre accueilli" wire:model="saisie.genre" :options="[Genre::Fille->value => 'Filles', Genre::Garcon->value => 'Garçons']" requis
                        aide="Un internat n’accueille qu’un seul genre ; un établissement a au plus un internat par genre." />
                @endif

                @if (in_array($niveau, ['regions', 'provinces', 'complexes'], true))
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
