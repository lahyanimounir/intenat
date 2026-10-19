@php
    use App\Enums\StatutBatiment;
    use App\Enums\TypeChambre;
    use App\Support\Affichage\Format;
    use Illuminate\Support\Js;

    // Un état de lit se reconnaît à sa couleur ET à son libellé (infobulle, légende, fenêtre du lit).
    $etats = [
        'disponible' => ['Disponible', 'border-succes-bord bg-succes-fond text-succes'],
        'reserve' => ['Réservé, arrivée attendue', 'border-info-bord bg-info-fond text-info'],
        'occupe' => ['Occupé', 'border-marine-200 bg-marine-100 text-marine'],
        'en_maintenance' => ['Chambre en maintenance', 'border-alerte-bord bg-alerte-fond text-alerte'],
        'hors_service' => ['Hors service', 'border-neutre-bord bg-neutre-fond text-neutre'],
    ];
@endphp
<div>
    <x-page.entete titre="Plan d’occupation" :description="$choisi ? $choisi->nom.' — '.$choisi->etablissement->nom_efp : 'Bâtiments, chambres et lits de l’internat.'">
        <x-slot:actions>
            @if ($gerer)
                <x-bouton icone="file-spreadsheet" :href="url('/hebergement/import')">Importer un CSV</x-bouton>
                <x-bouton variante="primaire" icone="plus" wire:click="preparerBatiment">Ajouter un bâtiment</x-bouton>
            @endif
        </x-slot:actions>
    </x-page.entete>

    {{-- Plusieurs internats en charge : l'internat s'atteint par la hiérarchie administrative. --}}
    @if ($filtre !== null)
        <div class="non-imprime mb-5 flex flex-wrap items-center gap-2" role="group" aria-label="Choix de l’internat">
            <x-filtres-hierarchiques :filtre="$filtre" :region="$region" :province="$province" :complexe="$complexe" :etablissement="$etablissement" />
            <x-filtre libelle="Internat" wire:model.live="internat" :options="$filtre['internats']" tous="à choisir" :actif="$internat !== ''"
                :disabled="$etablissement === ''" wire:key="filtre-internat-{{ $etablissement }}" />
        </div>
    @endif

    @if ($choisi === null && $filtre !== null)
        <x-carte>
            <x-vide titre="Choisissez un internat" icone="building-complex">
                Sélectionnez la région, la province, le complexe / CMC et l’établissement, puis l’internat dont vous voulez voir le plan.
            </x-vide>
        </x-carte>
    @elseif ($choisi === null)
        <x-carte><x-vide titre="Aucun internat dans votre périmètre" icone="building-complex" /></x-carte>
    @else
        <div class="mb-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <x-stat libelle="Lits" :valeur="Format::nombre($totaux['lits'])" />
            <x-stat libelle="Disponibles" :valeur="Format::nombre($totaux['disponible'])" ton="succes" />
            <x-stat libelle="Occupés" :valeur="Format::nombre($totaux['occupe'])" />
            <x-stat libelle="Réservés" :valeur="Format::nombre($totaux['reserve'])" detail="Arrivée attendue" />
            <x-stat libelle="En maintenance" :valeur="Format::nombre($totaux['en_maintenance'])" :ton="$totaux['en_maintenance'] > 0 ? 'alerte' : 'neutre'" />
            <x-stat libelle="Hors service" :valeur="Format::nombre($totaux['hors_service'])" />
        </div>

        <div class="mb-5 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-discret" aria-label="Légende">
            @foreach ($etats as [$libelle, $classes])
                <span class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="size-3 rounded-sm border {{ $classes }}"></span>{{ $libelle }}</span>
            @endforeach
        </div>

        @if ($batiments->isEmpty())
            <x-carte>
                <x-vide titre="Référentiel vide" icone="building-complex">
                    Aucun bâtiment n’est enregistré pour cet internat. Importez le fichier CSV du référentiel, ou ajoutez les bâtiments un à un.
                    @if ($gerer)
                        <x-slot:action><x-bouton variante="primaire" icone="file-spreadsheet" :href="url('/hebergement/import')">Importer le référentiel</x-bouton></x-slot:action>
                    @endif
                </x-vide>
            </x-carte>
        @endif

        <div class="space-y-6">
            @foreach ($batiments as $ligneBatiment)
                <x-carte wire:key="batiment-{{ $ligneBatiment->id }}">
                    <x-carte.entete :titre="$ligneBatiment->code.' — '.$ligneBatiment->nom"
                        :description="$ligneBatiment->chambres->count().' chambre(s) · '.($ligneBatiment->nombre_etages + 1).' niveau(x)'.($ligneBatiment->equipements_communs ? ' · '.$ligneBatiment->equipements_communs : '')">
                        <x-slot:actions>
                            <x-statut :etat="$ligneBatiment->statut" />
                            @if ($gerer)
                                <x-bouton taille="sm" variante="discret" icone="pencil" wire:click="preparerBatiment({{ $ligneBatiment->id }})">Modifier</x-bouton>
                                <x-bouton taille="sm" variante="danger-discret" icone="trash" aria-label="Supprimer le bâtiment {{ $ligneBatiment->code }}"
                                    x-on:click="$confirmer({ titre: 'Supprimer le bâtiment {{ $ligneBatiment->code }} ?', texte: 'Ses étages, chambres et lits seront supprimés. Impossible si un lit a déjà été attribué ou si une chambre a un historique de maintenance.', libelle: 'Supprimer', destructif: true }).then(() => $wire.supprimerBatiment({{ $ligneBatiment->id }}))">Supprimer</x-bouton>
                                <x-bouton taille="sm" icone="plus" wire:click="ajouterEtage({{ $ligneBatiment->id }})" cible="ajouterEtage({{ $ligneBatiment->id }})">Étage</x-bouton>
                                <x-bouton taille="sm" icone="plus" wire:click="preparerChambre({{ $ligneBatiment->id }})">Chambre</x-bouton>
                            @endif
                        </x-slot:actions>
                    </x-carte.entete>

                    @php $parEtage = $ligneBatiment->chambres->groupBy('etage'); @endphp
                    @if ($ligneBatiment->chambres->isEmpty() && ! $gerer)
                        <x-vide titre="Aucune chambre" class="py-8!" />
                    @else
                        {{-- Chaque niveau du bâtiment, même vide : le gestionnaire y ajoute, renumérote ou supprime. --}}
                        @foreach (range(0, $ligneBatiment->nombre_etages) as $etage)
                            @php $chambres = $parEtage->get($etage, collect()); $nomEtage = $etage === 0 ? 'Rez-de-chaussée' : 'Étage '.$etage; @endphp
                            @continue($chambres->isEmpty() && ! $gerer)
                            <div class="border-b border-bord-doux px-5 py-4 last:border-0" wire:key="etage-{{ $ligneBatiment->id }}-{{ $etage }}">
                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-medium tracking-wider text-discret uppercase">{{ $nomEtage }}</p>
                                    @if ($gerer)
                                        <div class="flex flex-wrap gap-1">
                                            <x-bouton taille="sm" variante="discret" icone="plus" wire:click="preparerChambre({{ $ligneBatiment->id }}, null, {{ $etage }})">Chambre</x-bouton>
                                            @if ($chambres->isNotEmpty())
                                                <x-bouton taille="sm" variante="discret" icone="pencil" wire:click="preparerEtage({{ $ligneBatiment->id }}, {{ $etage }})">Renuméroter</x-bouton>
                                            @endif
                                            <x-bouton taille="sm" variante="danger-discret" icone="trash" aria-label="Supprimer : {{ $nomEtage }}"
                                                x-on:click="$confirmer({ titre: {{ Js::from('Supprimer : '.$nomEtage.' ?') }}, texte: {{ Js::from($chambres->count().' chambre(s) et leurs lits seront supprimés.'.($etage === $ligneBatiment->nombre_etages && $etage > 0 ? ' L’étage est retiré du bâtiment.' : ' Le niveau reste, vide.')) }}, libelle: 'Supprimer', destructif: true }).then(() => $wire.supprimerEtage({{ $ligneBatiment->id }}, {{ $etage }}))">Supprimer</x-bouton>
                                        </div>
                                    @endif
                                </div>
                                @if ($chambres->isEmpty())
                                    <p class="text-sm text-discret">Aucune chambre à cet étage.</p>
                                @endif
                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                                    @foreach ($chambres as $ligneChambre)
                                        <div class="rounded-md border border-bord bg-surface-2 px-3 py-2.5" wire:key="chambre-{{ $ligneChambre->id }}">
                                            <div class="mb-2 flex items-start justify-between gap-2">
                                                <div class="min-w-0">
                                                    <p class="chiffres text-base font-semibold text-encre">Chambre {{ $ligneChambre->numero }}</p>
                                                    <p class="truncate text-xs text-discret">
                                                        {{ $ligneChambre->type_chambre->libelle() }} · {{ $ligneChambre->lits->count() }}/{{ $ligneChambre->capacite }} lit(s)
                                                    </p>
                                                </div>
                                                @if ($gerer)
                                                    <div class="flex shrink-0">
                                                        <x-bouton taille="icone" variante="discret" aria-label="Modifier la chambre {{ $ligneChambre->numero }}" wire:click="preparerChambre({{ $ligneBatiment->id }}, {{ $ligneChambre->id }})"><x-icone nom="pencil" /></x-bouton>
                                                        @if ($ligneChambre->lits->count() < $ligneChambre->capacite)
                                                            <x-bouton taille="icone" variante="discret" aria-label="Ajouter un lit à la chambre {{ $ligneChambre->numero }}" wire:click="preparerLit({{ $ligneChambre->id }})"><x-icone nom="plus" /></x-bouton>
                                                        @endif
                                                        <x-bouton taille="icone" variante="discret" aria-label="Supprimer la chambre {{ $ligneChambre->numero }}"
                                                            x-on:click="$confirmer({ titre: {{ Js::from('Supprimer la chambre '.$ligneChambre->numero.' ?') }}, texte: 'Ses lits seront supprimés. Impossible si un lit a déjà été attribué ou si la chambre a un historique de maintenance.', libelle: 'Supprimer', destructif: true }).then(() => $wire.supprimerChambre({{ $ligneChambre->id }}))"><x-icone nom="trash" /></x-bouton>
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="flex flex-wrap gap-1.5">
                                                @forelse ($ligneChambre->lits as $ligneLit)
                                                    @php
                                                        $occupant = $nominatif ? $ligneLit->affectationActive?->demande->stagiaire : null;
                                                        $titre = 'Lit '.$ligneLit->numero.' — '.$etats[$ligneLit->etat][0].($occupant ? ' : '.$occupant->nomComplet() : '');
                                                    @endphp
                                                    <button type="button" wire:click="voirLit({{ $ligneLit->id }})" title="{{ $titre }}" aria-label="{{ $titre }}"
                                                        class="chiffres inline-flex h-7 min-w-9 items-center justify-center rounded-sm border px-1.5 text-xs font-semibold transition-opacity hover:opacity-80 {{ $etats[$ligneLit->etat][1] }}">
                                                        {{ $ligneLit->numero }}
                                                    </button>
                                                @empty
                                                    <span class="text-xs text-discret">Aucun lit</span>
                                                @endforelse
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    @endif
                </x-carte>
            @endforeach
        </div>
    @endif

    {{-- ------------------------------------------------------------------ Fenêtres --}}
    @if ($dialogue === 'batiment')
        <x-dialogue :titre="$ligne ? 'Modifier le bâtiment' : 'Ajouter un bâtiment'">
            <form wire:submit="enregistrerBatiment" id="form-batiment" novalidate class="grid gap-4 sm:grid-cols-2">
                <x-saisie libelle="Code" wire:model="batiment.code" requis autofocus aide="Court et unique dans l’internat, par exemple A." />
                <x-saisie libelle="Nom" wire:model="batiment.nom" requis />
                <x-saisie libelle="Nombre d’étages" wire:model="batiment.nombre_etages" type="number" min="0" max="30" requis aide="0 pour un bâtiment de plain-pied." />
                <x-liste libelle="Statut" wire:model="batiment.statut" :options="StatutBatiment::options()" requis />
                <x-zone-texte libelle="Équipements communs" wire:model="batiment.equipements_communs" rows="2" class="sm:col-span-2" />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-batiment" variante="primaire" cible="enregistrerBatiment">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'chambre' && $batimentDeLaChambre)
        <x-dialogue :titre="$ligne ? 'Modifier la chambre' : 'Ajouter une chambre'" :description="'Bâtiment '.$batimentDeLaChambre->code.' — '.$batimentDeLaChambre->nom">
            <form wire:submit="enregistrerChambre" id="form-chambre" novalidate class="grid gap-4 sm:grid-cols-2">
                <x-saisie libelle="Numéro" wire:model="chambre.numero" requis autofocus />
                <x-saisie libelle="Étage" wire:model="chambre.etage" type="number" min="0" :max="$batimentDeLaChambre->nombre_etages" requis aide="0 pour le rez-de-chaussée." />
                <x-liste libelle="Type de chambre" wire:model="chambre.type_chambre" :options="TypeChambre::options()" requis
                    aide="Les chambres Isolement et Infirmerie ne comptent pas dans les places offertes." />
                <x-saisie libelle="Capacité (lits)" wire:model="chambre.capacite" type="number" min="1" max="20" requis />
                <x-zone-texte libelle="Équipements" wire:model="chambre.equipements" rows="2" class="sm:col-span-2" />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-chambre" variante="primaire" cible="enregistrerChambre">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'etage' && $etageVise !== null)
        <x-dialogue titre="Renuméroter l’étage" :description="($etageVise === 0 ? 'Rez-de-chaussée' : 'Étage '.$etageVise).' — ses chambres passent à l’étage choisi, qui doit être vide.'" largeur="sm">
            <form wire:submit="renumeroterEtage" id="form-etage" novalidate>
                <x-saisie libelle="Nouveau numéro d’étage" wire:model="nouvelEtage" type="number" min="0" requis autofocus aide="0 pour le rez-de-chaussée." />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-etage" variante="primaire" cible="renumeroterEtage">Renuméroter</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'nouveau-lit')
        <x-dialogue titre="Ajouter un lit" largeur="sm">
            <form wire:submit="ajouterLit" id="form-lit" novalidate>
                <x-saisie libelle="Numéro du lit" wire:model="numeroLit" requis autofocus aide="Unique dans la chambre." />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-lit" variante="primaire" cible="ajouterLit">Ajouter</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'lit' && $litOuvert)
        @php $occupant = $nominatif ? $litOuvert->affectationActive?->demande->stagiaire : null; @endphp
        <x-dialogue :titre="'Lit '.$litOuvert->numero.' — chambre '.$litOuvert->chambre->numero" :description="'Bâtiment '.$litOuvert->chambre->batiment->code.' · '.$litOuvert->chambre->type_chambre->libelle()">
            <div class="space-y-5">
                <x-definitions>
                    <x-definition terme="État"><span class="inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-px text-xs font-medium {{ $etats[$litOuvert->etat][1] }}">{{ $etats[$litOuvert->etat][0] }}</span></x-definition>
                    <x-definition terme="Occupant">
                        @if ($occupant)
                            <a href="{{ url('/hebergement/residents/'.$litOuvert->affectationActive->id) }}" wire:navigate class="text-bleu hover:underline">{{ $occupant->nomComplet() }}</a>
                            <span class="chiffres block text-xs text-discret">CEF {{ $occupant->cef }}</span>
                        @elseif ($litOuvert->affectationActive)
                            Lit attribué
                        @endif
                    </x-definition>
                </x-definitions>

                @if ($nominatif)
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-encre">Historique des occupants</h3>
                        @if ($litOuvert->historique->isEmpty())
                            <p class="text-sm text-discret">Ce lit n’a encore jamais été attribué.</p>
                        @else
                            <ul class="divide-y divide-bord-doux rounded-md border border-bord">
                                @foreach ($litOuvert->historique as $periode)
                                    <li class="px-3.5 py-2.5">
                                        <p class="text-sm font-medium text-encre">{{ $periode->demande->stagiaire->nomComplet() }}</p>
                                        <p class="chiffres text-xs text-discret">
                                            du {{ Format::date($periode->date_debut) }} {{ $periode->date_fin ? 'au '.Format::date($periode->date_fin) : '— en cours' }}
                                            · attribué par {{ $periode->affecteur?->nomComplet() }}
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </div>
            @if ($gerer)
                <form wire:submit="renommerLit" class="mt-5 flex items-end gap-2 border-t border-bord-doux pt-4" novalidate>
                    <div class="w-40"><x-saisie libelle="Numéro du lit" wire:model="numeroLit" requis /></div>
                    <x-bouton type="submit" icone="pencil" cible="renommerLit">Renommer</x-bouton>
                </form>
            @endif
            <x-slot:pied>
                @if ($gerer && ! $litOuvert->affectationActive && $litOuvert->historique->isEmpty())
                    <x-bouton variante="danger-discret" icone="trash" class="mr-auto"
                        x-on:click="$confirmer({ titre: 'Supprimer ce lit ?', texte: 'Le lit n’a jamais été attribué : il est retiré du référentiel.', libelle: 'Supprimer', destructif: true }).then(() => $wire.supprimerLit())">Supprimer le lit</x-bouton>
                @endif
                @if ($gerer && $litOuvert->etat === 'hors_service')
                    <x-bouton icone="power" wire:click="basculerLit(false)" cible="basculerLit">Remettre en service</x-bouton>
                @elseif ($gerer && ! $litOuvert->affectationActive)
                    <x-bouton variante="danger-discret" icone="power" wire:click="basculerLit(true)" cible="basculerLit">Mettre hors service</x-bouton>
                @endif
                <x-bouton wire:click="fermerDialogue">Fermer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
