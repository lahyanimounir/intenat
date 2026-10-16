@php
    use App\Enums\TypeRegime;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Repas" description="Réservez vos repas pour les quatorze prochains jours. Une réservation se fait ou s’annule jusqu’à l’heure limite, la veille du repas." />

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="space-y-4">
            @if (! $resident)
                <x-carte>
                    <x-vide titre="Réservation réservée aux résidents" icone="utensils-crossed">
                        La réservation des repas est ouverte aux stagiaires hébergés à l’internat.
                    </x-vide>
                </x-carte>
            @elseif ($jours->isEmpty())
                <x-carte><x-vide titre="Aucun menu publié pour les prochains jours" icone="soup">Les menus apparaissent ici dès leur validation par le responsable de la restauration.</x-vide></x-carte>
            @else
                @foreach ($jours as $date => $repas)
                    <x-carte wire:key="jour-{{ $date }}">
                        <header class="border-b border-bord-doux px-5 py-3"><h2 class="text-md font-semibold text-encre">{{ Format::jour($date) }}</h2></header>
                        <ul>
                            @foreach ($repas as $ligne)
                                <li class="flex flex-wrap items-start justify-between gap-3 border-b border-bord-doux px-5 py-3 last:border-0" wire:key="menu-{{ $ligne->menu->id }}">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-base font-medium">{{ $ligne->menu->type_repas->libelle() }}</p>
                                        <p class="mt-0.5 text-sm text-encre-2">{{ $ligne->menu->description }}</p>
                                        <p class="mt-1 text-xs text-discret">
                                            @if ($ligne->modifiable)
                                                Réservation ou annulation jusqu’au {{ Format::dateHeure($ligne->limite) }}
                                            @else
                                                Réservations closes
                                            @endif
                                        </p>
                                        @if ($ligne->blocage && $ligne->modifiable)<p class="mt-1 text-sm text-alerte">{{ $ligne->blocage }}</p>@endif
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($ligne->reservation)<x-statut :etat="$ligne->reservation->statut" />@endif
                                        @if ($ligne->modifiable && $ligne->reserve)
                                            <x-bouton taille="sm" wire:click="annuler({{ $ligne->reservation->id }})" cible="annuler({{ $ligne->reservation->id }})">Annuler</x-bouton>
                                        @elseif ($ligne->modifiable && ! $ligne->blocage)
                                            <x-bouton taille="sm" variante="primaire" wire:click="reserver({{ $ligne->menu->id }})" cible="reserver({{ $ligne->menu->id }})">Réserver</x-bouton>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-carte>
                @endforeach
            @endif
        </div>

        <x-carte>
            <x-carte.entete titre="Mes régimes alimentaires" description="Allergies et restrictions à signaler à la restauration.">
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

    @if ($dialogue === 'regime')
        <x-dialogue titre="Déclarer un régime alimentaire" description="Un justificatif médical est demandé pour une allergie ou une restriction médicale.">
            <div class="space-y-4">
                <x-liste libelle="Type de régime" wire:model.live="type" :options="TypeRegime::options()" vide="Choisir…" requis />
                <x-saisie libelle="Précision" wire:model="precision" aide="Par exemple : arachides, sans gluten." />
                <div>
                    <p class="mb-1.5 text-sm font-medium text-encre">Justificatif @if ($type !== '' && $type !== TypeRegime::RestrictionReligieuse->value)<span aria-hidden="true" class="text-danger">*</span>@endif</p>
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
