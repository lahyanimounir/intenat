@php
    use App\Support\Affichage\Format;

    $aujourdhui = now()->toDateString();
    $demain = now()->addDay()->toDateString();
@endphp
<div>
    <x-page.entete titre="Service et effectifs"
        description="Pour chaque repas : ce qui est réservé par les stagiaires, ce que vous confirmez la veille (J+1), et ce qui est réellement servi.">
        @if ($internats->count() > 1)
            <x-slot:actions><div class="w-56"><x-liste wire:model.live="internat" :options="$internats" vide="Tous les internats" aria-label="Internat" /></div></x-slot:actions>
        @endif
    </x-page.entete>

    <div class="mb-5 flex flex-wrap items-center gap-2">
        <x-bouton taille="sm" :variante="$date->toDateString() === $aujourdhui ? 'primaire' : 'secondaire'" wire:click="choisir('aujourdhui')">Aujourd’hui</x-bouton>
        <x-bouton taille="sm" :variante="$date->toDateString() === $demain ? 'primaire' : 'secondaire'" wire:click="choisir('demain')">Demain (J+1)</x-bouton>
        <div class="w-40"><x-saisie type="date" taille="sm" aria-label="Autre jour" wire:model.live="jour" /></div>
        <p class="ml-2 text-base font-medium" aria-live="polite">{{ Format::jour($date) }}</p>
    </div>

    @if ($menus->isEmpty())
        <x-carte><x-vide titre="Aucun menu validé pour ce jour" icone="utensils-crossed">Les effectifs se suivent sur les menus validés.</x-vide></x-carte>
    @else
        <div class="grid gap-4 xl:grid-cols-2" wire:loading.class="opacity-60" wire:target="choisir,jour,internat">
            @foreach ($menus as $menu)
                @php
                    $service = $menu->service;
                    $confirme = $service?->effectif_confirme;
                    $clos = $service?->date_cloture_service !== null;
                @endphp
                <x-carte class="px-5 py-4" wire:key="service-{{ $menu->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-md font-semibold">{{ $menu->type_repas->libelle() }} <span class="font-normal text-discret">· {{ $menu->internat->nom }}</span></h2>
                            <p class="mt-0.5 text-sm whitespace-pre-line text-discret">{{ $menu->description }}</p>
                        </div>
                        @if ($clos)
                            <x-badge :ton="$service->conforme ? 'succes' : 'alerte'">{{ $service->conforme ? 'Service conforme' : 'Écart constaté' }}</x-badge>
                        @elseif ($confirme !== null)
                            <x-badge ton="info">Effectif confirmé</x-badge>
                        @else
                            <x-badge ton="alerte">Effectif à confirmer</x-badge>
                        @endif
                    </div>

                    <dl class="mt-4 grid grid-cols-3 gap-3">
                        @foreach (['Réservés' => $confirme !== null ? $service->effectif_reserve : $menu->reserves, 'Confirmés' => $confirme, 'Servis' => $service?->effectif_servi] as $libelle => $valeur)
                            <div class="rounded-md bg-surface-2 px-3 py-2.5">
                                <dt class="text-xs text-discret">{{ $libelle }}</dt>
                                <dd class="chiffres text-xl font-semibold">{{ $valeur ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($service?->observation)<p class="mt-3 text-sm text-encre-2">Observation : {{ $service->observation }}</p>@endif

                    @if ($gerer && ! $clos)
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($confirme === null)
                                <x-bouton variante="primaire" taille="sm" icone="users" wire:click="preparerEffectif({{ $menu->id }})">Confirmer l’effectif</x-bouton>
                            @else
                                <x-bouton variante="primaire" taille="sm" icone="clipboard-check" :disabled="$futur" wire:click="preparerCloture({{ $menu->id }})">Clore le service</x-bouton>
                                @if ($futur)<p class="self-center text-sm text-discret">Le service se clôt le jour du repas.</p>@endif
                            @endif
                        </div>
                    @endif
                </x-carte>
            @endforeach
        </div>
    @endif

    @if ($dialogue === 'effectif' && $choisi)
        <x-dialogue titre="Confirmer l’effectif prévisionnel" largeur="lg"
            :description="$choisi->type_repas->libelle().' du '.Format::dateLongue($choisi->date_repas).' — '.$choisi->internat->nom">
            <form wire:submit="confirmer" id="form-effectif" novalidate class="space-y-4">
                <p class="text-base text-encre-2">
                    <strong class="chiffres text-encre">{{ $choisi->reserves }}</strong> stagiaire(s) ont réservé ce repas. L’effectif confirmé est celui que vous arrêtez pour la
                    préparation ; après confirmation, plus aucune réservation ni annulation n’est possible.
                </p>
                @if ($reservations->isNotEmpty())
                    @php $aSurveiller = $reservations->filter(fn ($r) => $r->allergie || $r->stagiaire->regimes->isNotEmpty())->count(); @endphp
                    <div>
                        <p class="mb-1.5 text-sm font-medium">
                            Stagiaires concernés
                            @if ($aSurveiller > 0)<x-badge ton="alerte" class="ml-1">{{ $aSurveiller }} avec régime ou allergie</x-badge>@endif
                        </p>
                        <ul class="defilement-fin max-h-64 divide-y divide-bord-doux overflow-y-auto rounded-md border border-bord text-sm">
                            @foreach ($reservations as $reservation)
                                @php $attention = $reservation->allergie || $reservation->stagiaire->regimes->isNotEmpty(); @endphp
                                <li @class(['px-3.5 py-2', 'bg-alerte-fond' => $attention]) wire:key="effectif-{{ $reservation->id }}">
                                    <p class="font-medium">{{ $reservation->stagiaire->nom }} {{ $reservation->stagiaire->prenom }} <span class="font-normal text-discret">· {{ $reservation->stagiaire->cef }}</span></p>
                                    <p class="text-xs text-discret">
                                        {{ $reservation->stagiaire->regimes->isNotEmpty() ? $reservation->stagiaire->regimes->map(fn ($r) => $r->type->libelle().($r->precision_regime ? ' — '.$r->precision_regime : ''))->implode(' · ') : 'Aucun régime déclaré' }}
                                    </p>
                                    @if ($reservation->allergie)<p class="text-xs font-medium text-alerte">Allergie pour ce repas : {{ $reservation->allergie }}</p>@endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <x-saisie libelle="Effectif confirmé" type="number" min="0" inputmode="numeric" wire:model="effectif" requis autofocus class="w-32" />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-effectif" variante="primaire" cible="confirmer">Confirmer l’effectif</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'cloture' && $choisi)
        @php $confirme = (int) $choisi->service?->effectif_confirme; @endphp
        <x-dialogue titre="Clore le service" largeur="lg"
            :description="$choisi->type_repas->libelle().' du '.Format::dateLongue($choisi->date_repas).' — '.$choisi->internat->nom">
            <form wire:submit="clore" id="form-cloture" novalidate class="space-y-4"
                x-data="{
                    pointage: $wire.entangle('pointage'), servis: $wire.entangle('servis'), nominatif: {{ $reservations->isNotEmpty() ? 'true' : 'false' }}, confirme: {{ $confirme }},
                    get total() { return this.nominatif ? Object.values(this.pointage).filter((etat) => etat === 'servi').length : Number(this.servis || 0) },
                }">
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-md border border-bord px-3 py-2.5">
                        <p class="text-xs text-discret">Réservés</p>
                        <p class="chiffres text-xl font-semibold">{{ $choisi->service?->effectif_reserve ?? $choisi->reserves }}</p>
                    </div>
                    <div class="rounded-md border border-bord px-3 py-2.5">
                        <p class="text-xs text-discret">Confirmés</p>
                        <p class="chiffres text-xl font-semibold">{{ $confirme }}</p>
                    </div>
                    <div class="rounded-md border border-bord px-3 py-2.5">
                        <p class="text-xs text-discret">Servis</p>
                        <p class="chiffres text-xl font-semibold" x-text="total" aria-live="polite">{{ $reservations->count() }}</p>
                    </div>
                </div>

                <div x-show="total !== confirme" @if ($reservations->count() === $confirme) x-cloak @endif>
                    <x-alerte ton="alerte">
                        Le nombre de repas servis (<span x-text="total">{{ $reservations->count() }}</span>) diffère de l’effectif confirmé ({{ $confirme }}) : le service sera marqué « non conforme ».
                    </x-alerte>
                </div>

                @if ($reservations->isNotEmpty())
                    <fieldset>
                        <legend class="mb-1.5 text-sm font-medium">Pointage des réservations</legend>
                        <ul class="defilement-fin max-h-72 divide-y divide-bord-doux overflow-y-auto rounded-md border border-bord">
                            @foreach ($reservations as $reservation)
                                @php $nom = $reservation->stagiaire->nom.' '.$reservation->stagiaire->prenom; @endphp
                                <li class="flex flex-wrap items-center gap-3 px-3.5 py-2" wire:key="pointage-{{ $reservation->id }}">
                                    <span class="min-w-0 flex-1 text-base">
                                        {{ $nom }}
                                        @if ($reservation->stagiaire->regimes->isNotEmpty())
                                            <x-badge ton="info" class="ml-2">{{ $reservation->stagiaire->regimes->map(fn ($r) => $r->type->libelle().($r->precision_regime ? ' — '.$r->precision_regime : ''))->implode(' · ') }}</x-badge>
                                        @endif
                                        @if ($reservation->allergie)
                                            <x-badge ton="alerte" class="ml-1">Allergie : {{ $reservation->allergie }}</x-badge>
                                        @endif
                                    </span>
                                    <div role="radiogroup" aria-label="Présence de {{ $nom }}" class="flex overflow-hidden rounded-md border border-bord text-sm">
                                        <label class="cursor-pointer">
                                            <input type="radio" class="peer sr-only" value="servi" x-model="pointage[{{ $reservation->id }}]" />
                                            <span class="block bg-surface px-3 py-1 font-medium text-encre-2 transition-colors peer-checked:bg-marine peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:-outline-offset-2 peer-focus-visible:outline-bleu hover:bg-marine-50 peer-checked:hover:bg-marine">Servi</span>
                                        </label>
                                        <label class="cursor-pointer">
                                            <input type="radio" class="peer sr-only" value="absent" x-model="pointage[{{ $reservation->id }}]" />
                                            <span class="block bg-surface px-3 py-1 font-medium text-encre-2 transition-colors peer-checked:bg-danger peer-checked:text-white peer-focus-visible:outline-2 peer-focus-visible:-outline-offset-2 peer-focus-visible:outline-bleu hover:bg-marine-50 peer-checked:hover:bg-danger">Absent</span>
                                        </label>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </fieldset>
                @else
                    <x-saisie libelle="Nombre de repas servis" type="number" min="0" inputmode="numeric" wire:model="servis" requis class="w-32" aide="Aucune réservation nominative à pointer pour ce repas." />
                @endif

                <x-zone-texte libelle="Observation" wire:model="observation" rows="2" maxlength="500" />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-cloture" variante="primaire" cible="clore">Clore le service</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
