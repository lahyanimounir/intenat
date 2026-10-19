@php
    use App\Enums\TypeRegime;
    use App\Support\Affichage\Format;

    $typeChoisi = TypeRegime::tryFrom($type);
@endphp
<div>
    <x-page.entete titre="Repas" description="Réservez vos repas du lendemain ou de toute la semaine, en signalant si besoin une allergie pour chaque repas. Une réservation se fait, se modifie ou s’annule jusqu’à l’heure limite, la veille du repas." />

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="space-y-4">
            @if (! $resident)
                <x-carte>
                    <x-vide titre="Réservation réservée aux résidents" icone="utensils-crossed">
                        La réservation des repas est ouverte aux stagiaires hébergés à l’internat.
                    </x-vide>
                </x-carte>
            @elseif ($jours->isEmpty())
                <x-carte><x-vide titre="Aucun menu publié pour les prochains jours" icone="soup">Les menus apparaissent ici dès leur validation par le chef de pôle.</x-vide></x-carte>
            @else
                @if ($reservables > 0)
                    <x-carte>
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm text-discret">Cocher :</span>
                                <x-bouton taille="sm" wire:click="selectionner('demain')">Tous les repas de demain</x-bouton>
                                <x-bouton taille="sm" wire:click="selectionner('semaine')">Toute la semaine</x-bouton>
                                @if ($selection !== [])<x-bouton taille="sm" variante="discret" icone="x" wire:click="selectionner('aucun')">Décocher</x-bouton>@endif
                            </div>
                            <x-bouton taille="sm" variante="primaire" icone="check" wire:click="preparerSelection" :disabled="$selection === []">
                                Réserver la sélection ({{ count($selection) }})
                            </x-bouton>
                        </div>
                    </x-carte>
                @endif

                @foreach ($jours as $date => $repas)
                    <x-carte wire:key="jour-{{ $date }}">
                        <header class="border-b border-bord-doux px-5 py-3"><h2 class="text-md font-semibold text-encre">{{ Format::jour($date) }}</h2></header>
                        <ul>
                            @foreach ($repas as $ligne)
                                <li class="flex flex-wrap items-start justify-between gap-3 border-b border-bord-doux px-5 py-3 last:border-0" wire:key="menu-{{ $ligne->menu->id }}">
                                    <div class="flex min-w-0 flex-1 items-start gap-3">
                                        @if ($ligne->reservable)
                                            <x-case wire:model.live="selection" value="{{ $ligne->menu->id }}" class="mt-1" aria-label="Choisir le {{ mb_strtolower($ligne->menu->type_repas->libelle()) }} du {{ Format::jour($date) }}" />
                                        @endif
                                        <div class="min-w-0">
                                            <p class="text-base font-medium">{{ $ligne->menu->type_repas->libelle() }}</p>
                                            <p class="mt-0.5 text-sm text-encre-2">{{ $ligne->menu->description }}</p>
                                            @if ($ligne->reserve && $ligne->reservation->allergie)
                                                <p class="mt-1 text-sm text-alerte">Allergie signalée : {{ $ligne->reservation->allergie }}</p>
                                            @endif
                                            <p class="mt-1 text-xs text-discret">
                                                @if ($ligne->modifiable)
                                                    Réservation, modification ou annulation jusqu’au {{ Format::dateHeure($ligne->limite) }}
                                                @else
                                                    Réservations closes
                                                @endif
                                            </p>
                                            @if ($ligne->blocage && $ligne->modifiable)<p class="mt-1 text-sm text-alerte">{{ $ligne->blocage }}</p>@endif
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($ligne->reservation)<x-statut :etat="$ligne->reservation->statut" />@endif
                                        @if ($ligne->modifiable && $ligne->reserve)
                                            <x-bouton taille="sm" variante="discret" icone="pencil" wire:click="modifierAllergie({{ $ligne->reservation->id }})">Modifier</x-bouton>
                                            <x-bouton taille="sm" wire:click="annuler({{ $ligne->reservation->id }})" cible="annuler({{ $ligne->reservation->id }})">Annuler</x-bouton>
                                        @elseif ($ligne->reservable)
                                            <x-bouton taille="sm" variante="primaire" wire:click="preparerReservation({{ $ligne->menu->id }})">Réserver</x-bouton>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-carte>
                @endforeach
            @endif
        </div>

        <div class="space-y-4">
            <x-carte>
                <x-carte.entete titre="Mes réservations" description="Repas réservés à venir, avec l’allergie signalée pour chacun." />
                @if ($mesReservations->isEmpty())
                    <x-vide titre="Aucune réservation à venir" class="py-8!" />
                @else
                    <ul>
                        @foreach ($mesReservations as $ligne)
                            <li class="flex items-start justify-between gap-3 border-b border-bord-doux px-5 py-2.5 last:border-0" wire:key="recap-{{ $ligne->reservation->id }}">
                                <div class="min-w-0">
                                    <p class="text-base font-medium">{{ Format::jour($ligne->menu->date_repas) }} · {{ $ligne->menu->type_repas->libelle() }}</p>
                                    <p class="text-sm text-encre-2">{{ $ligne->menu->description }}</p>
                                    <p @class(['text-xs', 'text-alerte' => $ligne->reservation->allergie, 'text-discret' => ! $ligne->reservation->allergie])>
                                        {{ $ligne->reservation->allergie ? 'Allergie : '.$ligne->reservation->allergie : 'Aucune allergie signalée' }}
                                    </p>
                                </div>
                                @if ($ligne->modifiable)
                                    <x-bouton taille="icone" variante="discret" icone="pencil" aria-label="Modifier la réservation" wire:click="modifierAllergie({{ $ligne->reservation->id }})" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-carte>

            <x-carte>
                <x-carte.entete titre="Mes régimes alimentaires" description="Végétarien, régime spécifique, allergies et autres restrictions à signaler à la restauration.">
                    <x-slot:actions><x-bouton taille="sm" icone="plus" wire:click="ouvrirDialogue('regime')">Déclarer</x-bouton></x-slot:actions>
                </x-carte.entete>
                @if ($regimes->isEmpty())
                    <x-vide titre="Aucun régime déclaré" class="py-8!" />
                @else
                    <ul>
                        @foreach ($regimes as $regime)
                            <li class="flex items-start justify-between gap-3 border-b border-bord-doux px-5 py-3 last:border-0" wire:key="regime-{{ $regime->id }}">
                                <div class="min-w-0">
                                    <p class="text-base font-medium">{{ $regime->type->libelle() }}</p>
                                    @if ($regime->precision_regime)<p class="mt-0.5 text-sm text-encre-2">{{ $regime->precision_regime }}</p>@endif
                                    <p class="mt-0.5 text-xs text-discret">{{ $regime->justificatif_fichier ? 'Justificatif fourni' : 'Sans justificatif' }}</p>
                                </div>
                                <x-bouton taille="icone" variante="discret" aria-label="Retirer le régime {{ $regime->type->libelle() }}"
                                    x-on:click="$confirmer({ titre: 'Retirer ce régime ?', texte: 'La restauration ne sera plus informée de cette restriction.', libelle: 'Retirer', destructif: true }).then(() => $wire.retirerRegime({{ $regime->id }}))">
                                    <x-icone nom="trash" />
                                </x-bouton>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-carte>
        </div>
    </div>

    @if ($dialogue === 'reservation')
        <x-dialogue :titre="$choisis->count() > 1 ? 'Réserver '.$choisis->count().' repas' : 'Réserver ce repas'"
            description="Signalez une allergie ou une restriction propre à ce repas : elle sera transmise au responsable de la restauration.">
            <form wire:submit="reserverSelection" id="form-reservation" novalidate class="space-y-4">
                <ul class="max-h-56 space-y-1 overflow-y-auto rounded-md border border-bord bg-surface-2 px-3.5 py-2.5 text-sm">
                    @foreach ($choisis as $ligne)
                        <li wire:key="choisi-{{ $ligne->menu->id }}"><span class="font-medium">{{ Format::jour($ligne->menu->date_repas) }} · {{ $ligne->menu->type_repas->libelle() }}</span> — {{ $ligne->menu->description }}</li>
                    @endforeach
                </ul>
                <x-saisie libelle="Allergie ou restriction pour ce repas (facultatif)" wire:model="allergie" maxlength="255" aide="Par exemple : arachides, lactose, sans gluten." />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-reservation" variante="primaire" icone="check" cible="reserverSelection">Confirmer la réservation</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'allergie' && $enModification)
        <x-dialogue titre="Modifier la réservation" :description="Format::jour($enModification->menu->date_repas).' · '.$enModification->menu->type_repas->libelle()">
            <form wire:submit="enregistrerAllergie" id="form-allergie" novalidate class="space-y-4">
                <p class="rounded-md border border-bord bg-surface-2 px-3.5 py-2.5 text-sm text-encre-2">{{ $enModification->menu->description }}</p>
                <x-saisie libelle="Allergie ou restriction pour ce repas" wire:model="allergie" maxlength="255" autofocus aide="Laissez vide si vous n’avez rien à signaler. Pour ne plus manger ce repas, annulez la réservation." />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Fermer</x-bouton>
                <x-bouton type="submit" form="form-allergie" variante="primaire" cible="enregistrerAllergie">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'regime')
        <x-dialogue titre="Déclarer un régime alimentaire" description="Un justificatif médical est demandé pour une allergie ou une restriction médicale.">
            <div class="space-y-4">
                <x-liste libelle="Type de régime" wire:model.live="type" :options="TypeRegime::options()" vide="Choisir…" requis />
                <x-saisie libelle="Précision" wire:model="precision" :requis="$typeChoisi === TypeRegime::Autre" aide="Par exemple : arachides, sans gluten, sans porc." />
                <div>
                    <p class="mb-1.5 text-sm font-medium text-encre">Justificatif @if ($typeChoisi?->exigeJustificatif())<span aria-hidden="true" class="text-danger">*</span>@endif</p>
                    <x-fichier wire:model="justificatif" :fichier="$justificatif" />
                </div>
            </div>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton variante="primaire" wire:click="declarerRegime" cible="declarerRegime,justificatif">Déclarer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
