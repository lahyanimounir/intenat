@php
    use App\Enums\StatutSortie;
    use App\Support\Affichage\Format;
@endphp
<div>
    <x-page.entete titre="Sorties et absences"
        description="Décidez des demandes de sortie, faites l’appel et tenez le registre des absences. Une absence couverte par une sortie acceptée est autorisée ; les autres donnent lieu à une alerte.">
        @if ($internats->count() > 1)
            <x-slot:actions><div class="w-64"><x-liste wire:model.live="internat" :options="$internats" vide="Tous les internats" taille="sm" aria-label="Internat" /></div></x-slot:actions>
        @endif
    </x-page.entete>

    <x-onglets libelle="Sections">
        <x-onglet :actif="$onglet === 'sorties'" wire:click="voir('sorties')" :compteur="$enAttente">Demandes de sortie</x-onglet>
        <x-onglet :actif="$onglet === 'feuille'" wire:click="voir('feuille')">Feuille d’appel</x-onglet>
        <x-onglet :actif="$onglet === 'absences'" wire:click="voir('absences')">Registre des absences</x-onglet>
    </x-onglets>

    <div class="pt-5" wire:key="onglet-{{ $onglet }}">
        {{-- ----------------------------------------------------------- Sorties --}}
        @if ($onglet === 'sorties')
            <x-tableau legende="Demandes de sortie" :lignes="$sorties" vide="Aucune demande de sortie">
                <x-slot:barre>
                    <x-filtre libelle="Décision" wire:model.live="statut" :options="$statuts" tous="toutes" :actif="$statut !== ''" />
                </x-slot:barre>
                <x-slot:entete>
                    <x-tableau.colonne>Résident</x-tableau.colonne>
                    <x-tableau.colonne>Départ</x-tableau.colonne>
                    <x-tableau.colonne>Retour</x-tableau.colonne>
                    <x-tableau.colonne>Motif</x-tableau.colonne>
                    <x-tableau.colonne>Décision</x-tableau.colonne>
                    <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
                </x-slot:entete>
                @foreach ($sorties as $sortie)
                    <x-tableau.ligne wire:key="sortie-{{ $sortie->id }}">
                        <x-tableau.cellule>
                            <a href="{{ route('hebergement.residents.fiche', $sortie->affectation_id) }}" wire:navigate class="font-medium text-marine hover:underline">
                                {{ $sortie->affectation->demande->stagiaire->nom }} {{ $sortie->affectation->demande->stagiaire->prenom }}
                            </a>
                            <p class="chiffres text-xs text-discret">Chambre {{ $sortie->affectation->lit->chambre->numero }}</p>
                        </x-tableau.cellule>
                        <x-tableau.cellule class="chiffres whitespace-nowrap">{{ Format::dateHeure($sortie->date_depart) }}</x-tableau.cellule>
                        <x-tableau.cellule class="chiffres whitespace-nowrap">{{ Format::dateHeure($sortie->date_retour) }}</x-tableau.cellule>
                        <x-tableau.cellule class="max-w-xs">{{ $sortie->motif }}</x-tableau.cellule>
                        <x-tableau.cellule>
                            <x-statut :etat="$sortie->statut" />
                            @if ($sortie->traitant)<p class="mt-1 text-xs text-discret">{{ $sortie->traitant->nomComplet() }} · {{ Format::date($sortie->date_traitement) }}</p>@endif
                            @if ($sortie->motif_refus)<p class="mt-1 text-sm text-discret">{{ $sortie->motif_refus }}</p>@endif
                        </x-tableau.cellule>
                        <x-tableau.cellule droite>
                            @if ($sortie->statut === StatutSortie::EnAttente)
                                <div class="flex justify-end gap-1.5">
                                    <x-bouton taille="sm" variante="primaire" icone="check" wire:click="accepter({{ $sortie->id }})" cible="accepter({{ $sortie->id }})">Accepter</x-bouton>
                                    <x-bouton taille="sm" variante="danger-discret" icone="x"
                                        x-on:click="$motif({ titre: 'Refuser la sortie', description: 'Le motif est communiqué au résident.', libelle: 'Motif du refus', libelleAction: 'Refuser', destructif: true }).then((motif) => $wire.refuser({{ $sortie->id }}, motif))">
                                        Refuser
                                    </x-bouton>
                                </div>
                            @endif
                        </x-tableau.cellule>
                    </x-tableau.ligne>
                @endforeach
            </x-tableau>
        @endif

        {{-- ----------------------------------------------------- Feuille d'appel --}}
        @if ($onglet === 'feuille')
            @if ($choisi === null)
                <x-carte><x-vide titre="Aucun internat dans votre périmètre" /></x-carte>
            @else
                <x-tableau legende="Feuille d’appel" :lignes="$residents" :plafonne="false" vide="Aucun résident hébergé à cette date">
                    <x-slot:barre>
                        <div class="min-w-0 flex-1">
                            <p class="text-md font-semibold text-encre">{{ $choisi->nom }} — {{ Format::jour($date) }}</p>
                            <p class="text-sm text-discret">Cochez les résidents absents, puis enregistrez. Un résident déjà noté absent ne l’est pas deux fois.</p>
                        </div>
                        <div class="w-44"><x-saisie type="date" taille="sm" value="{{ $date->toDateString() }}" max="{{ now()->toDateString() }}" aria-label="Jour de l’appel" wire:change="$set('jour', $event.target.value)" /></div>
                        <x-bouton taille="sm" variante="primaire" icone="check" wire:click="saisir" cible="saisir" :disabled="$residents->isEmpty()">Enregistrer les absences</x-bouton>
                    </x-slot:barre>
                    <x-slot:entete>
                        <x-tableau.colonne class="w-10"><span class="sr-only">Absent</span></x-tableau.colonne>
                        <x-tableau.colonne>Chambre et lit</x-tableau.colonne>
                        <x-tableau.colonne>Résident</x-tableau.colonne>
                        <x-tableau.colonne>Sortie acceptée</x-tableau.colonne>
                        <x-tableau.colonne>Ce jour-là</x-tableau.colonne>
                    </x-slot:entete>
                    @foreach ($residents as $resident)
                        @php $absence = $resident->absences->first(); @endphp
                        <x-tableau.ligne wire:key="appel-{{ $resident->id }}">
                            <x-tableau.cellule>
                                @if (! $absence)
                                    <x-case wire:model="absents" value="{{ $resident->id }}" aria-label="{{ $resident->demande->stagiaire->nom }} {{ $resident->demande->stagiaire->prenom }} absent" />
                                @endif
                            </x-tableau.cellule>
                            <x-tableau.cellule class="chiffres">{{ $resident->lit->chambre->numero }} — {{ $resident->lit->numero }}</x-tableau.cellule>
                            <x-tableau.cellule>
                                <span class="font-medium">{{ $resident->demande->stagiaire->nom }} {{ $resident->demande->stagiaire->prenom }}</span>
                                <span class="chiffres block text-xs text-discret">CEF {{ $resident->demande->stagiaire->cef }}</span>
                            </x-tableau.cellule>
                            <x-tableau.cellule><x-oui-non :valeur="$resident->sorties->isNotEmpty()" /></x-tableau.cellule>
                            <x-tableau.cellule>
                                @if ($absence)
                                    <x-badge :ton="$absence->autorisee ? 'neutre' : 'danger'">{{ $absence->autorisee ? 'Absent — autorisé' : 'Absent — non autorisé' }}</x-badge>
                                @else
                                    <span class="text-discret">Présent</span>
                                @endif
                            </x-tableau.cellule>
                        </x-tableau.ligne>
                    @endforeach
                </x-tableau>
            @endif
        @endif

        {{-- --------------------------------------------------------- Registre --}}
        @if ($onglet === 'absences')
            <x-tableau legende="Registre des absences" :lignes="$absences" vide="Aucune absence enregistrée">
                <x-slot:barre>
                    <x-case wire:model.live="nonAutorisees" libelle="Absences non autorisées seulement" />
                </x-slot:barre>
                <x-slot:entete>
                    <x-tableau.colonne>Date</x-tableau.colonne>
                    <x-tableau.colonne>Résident</x-tableau.colonne>
                    <x-tableau.colonne>Chambre</x-tableau.colonne>
                    <x-tableau.colonne>Nature</x-tableau.colonne>
                    <x-tableau.colonne>Saisie par</x-tableau.colonne>
                    <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
                </x-slot:entete>
                @foreach ($absences as $absence)
                    <x-tableau.ligne wire:key="absence-{{ $absence->id }}">
                        <x-tableau.cellule class="chiffres whitespace-nowrap">{{ Format::date($absence->date_absence) }}</x-tableau.cellule>
                        <x-tableau.cellule>
                            <a href="{{ route('hebergement.residents.fiche', $absence->affectation_id) }}" wire:navigate class="font-medium text-marine hover:underline">
                                {{ $absence->affectation->demande->stagiaire->nom }} {{ $absence->affectation->demande->stagiaire->prenom }}
                            </a>
                        </x-tableau.cellule>
                        <x-tableau.cellule class="chiffres">{{ $absence->affectation->lit->chambre->numero }}</x-tableau.cellule>
                        <x-tableau.cellule><x-badge :ton="$absence->autorisee ? 'neutre' : 'danger'">{{ $absence->autorisee ? 'Autorisée' : 'Non autorisée' }}</x-badge></x-tableau.cellule>
                        <x-tableau.cellule class="text-encre-2">{{ $absence->auteur?->nomComplet() }}</x-tableau.cellule>
                        <x-tableau.cellule droite>
                            <x-bouton taille="sm" variante="discret" icone="undo-2"
                                x-on:click="$confirmer({ titre: 'Annuler cette absence ?', texte: 'À n’utiliser que pour une saisie erronée. L’annulation reste consignée au journal d’audit.', libelle: 'Annuler l’absence', destructif: true }).then(() => $wire.annulerAbsence({{ $absence->id }}))">
                                Annuler
                            </x-bouton>
                        </x-tableau.cellule>
                    </x-tableau.ligne>
                @endforeach
            </x-tableau>
        @endif
    </div>
</div>
