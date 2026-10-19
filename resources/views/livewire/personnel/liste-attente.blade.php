@php use Illuminate\Support\Js; @endphp
<div>
    <x-page.entete titre="Liste d’attente"
        description="Quand un désistement ou un départ libère une place, vous êtes alerté. La liste d’attente n’est jamais promue automatiquement : vous choisissez le candidat et le faites passer directement en liste principale, sans confirmation de sa part." />

    @if ($listes->isEmpty())
        <x-carte><x-vide titre="Aucune campagne délibérée" icone="clipboard-list">La liste d’attente s’ouvre après la validation des admissions d’une campagne.</x-vide></x-carte>
    @else
        <div class="grid items-start gap-6 xl:grid-cols-2">
            @foreach ($listes as $liste)
                @php $premier = $liste->candidats->first(); @endphp
                <x-carte wire:key="liste-{{ $liste->campagne->id }}" :class="(string) $liste->campagne->id === $campagne ? 'ring-2 ring-marine-200' : null">
                    <x-carte.entete :titre="$liste->campagne->internat->nom" :description="'Campagne '.$liste->campagne->libellePeriode().' · '.$liste->campagne->internat->genre->libelle()">
                        <x-slot:actions>
                            <x-badge :ton="$liste->placesLibres > 0 ? 'alerte' : 'neutre'">{{ $liste->placesLibres }} place(s) libre(s)</x-badge>
                            <x-badge>{{ $liste->candidats->count() }} en attente</x-badge>
                        </x-slot:actions>
                    </x-carte.entete>

                    @if ($liste->candidats->isEmpty())
                        <x-vide titre="Liste d’attente vide" class="py-8!" />
                    @else
                        <ol>
                            @foreach ($liste->candidats as $candidat)
                                @php
                                    $horsOrdre = $premier && $candidat->id !== $premier->id && ($candidat->rang ?? 0) > ($premier->rang ?? 0);
                                    $nom = $candidat->stagiaire->nom.' '.$candidat->stagiaire->prenom;
                                @endphp
                                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-bord-doux px-5 py-3 last:border-0" wire:key="candidat-{{ $candidat->id }}">
                                    <span class="chiffres w-8 text-md font-semibold text-encre">{{ $candidat->rang }}</span>
                                    <div class="min-w-0 flex-1 basis-48">
                                        <p class="text-base font-medium">{{ $nom }}</p>
                                        <p class="chiffres text-sm text-discret">{{ $candidat->numero_dossier }} · score {{ $candidat->score }} / {{ config('internat.score.bareme_maximum') }}</p>
                                    </div>
                                    <x-bouton taille="sm" variante="discret" icone="calculator" wire:click="voirScore({{ $candidat->id }})">Score</x-bouton>
                                    @if ($peutAppeler)
                                        <x-bouton taille="sm" variante="primaire" icone="phone-call" :disabled="$liste->placesLibres < 1"
                                            x-on:click="$motif({
                                                titre: 'Passer le candidat à la liste principale',
                                                description: {{ Js::from($nom.' — rang '.$candidat->rang.'.'.($horsOrdre ? ' Ce candidat n’est pas le mieux classé de la liste d’attente : votre choix doit être motivé.' : '')) }},
                                                libelle: {{ Js::from($horsOrdre ? 'Motif de votre choix' : 'Observation (facultative)') }},
                                                libelleAction: 'Confirmer le transfert',
                                                minimum: {{ $horsOrdre ? 5 : 0 }},
                                            }).then((motif) => $wire.promouvoir({{ $candidat->id }}, motif))">
                                            Appeler ce candidat
                                        </x-bouton>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-carte>
            @endforeach
        </div>
    @endif

    @include('livewire.personnel.partiels.detail-score')
</div>
