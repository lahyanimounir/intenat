@php use App\Support\Affichage\Format; @endphp
<div>
    <x-page.entete titre="Affectations"
        description="Candidats admis ayant confirmé leur place, à loger. La permission d’occuper est votre décision : le paiement des frais d’internat n’est jamais une condition de l’affectation ou de l’arrivée." />

    <x-tableau legende="Candidats à loger" :lignes="$candidats" vide="Aucun candidat à loger"
        vide-description="Les candidats apparaissent ici dès qu’ils confirment leur place.">
        <x-slot:barre>
            <x-recherche placeholder="Nom, CEF ou n° de dossier" libelle="Rechercher un candidat" />
            @if ($internats->count() > 1)
                <x-filtre libelle="Internat" wire:model.live="internat" :options="$internats" :actif="$internat !== ''" />
            @endif
        </x-slot:barre>
        <x-slot:entete>
            <x-tableau.colonne>Candidat</x-tableau.colonne>
            <x-tableau.colonne>Confirmé le</x-tableau.colonne>
            <x-tableau.colonne>Paiement de l’hébergement</x-tableau.colonne>
            <x-tableau.colonne>Occupation</x-tableau.colonne>
            <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
        </x-slot:entete>

        @foreach ($candidats as $candidat)
            <x-tableau.ligne wire:key="candidat-{{ $candidat->id }}">
                <x-tableau.cellule>
                    <a href="{{ route('dossiers.fiche', $candidat) }}" wire:navigate class="font-medium text-marine hover:underline">{{ $candidat->stagiaire->nom }} {{ $candidat->stagiaire->prenom }}</a>
                    <p class="chiffres text-xs text-discret">{{ $candidat->numero_dossier }} · {{ $candidat->campagne->internat->nom }}</p>
                    @if ($candidat->besoins_specifiques)
                        <p class="mt-1 text-xs text-alerte">Besoins spécifiques{{ $candidat->precisions_besoins ? ' : '.$candidat->precisions_besoins : '' }}</p>
                    @endif
                </x-tableau.cellule>
                <x-tableau.cellule class="chiffres text-encre-2">{{ Format::date($candidat->date_confirmation) }}</x-tableau.cellule>
                <x-tableau.cellule>
                    @if ($candidat->dernierPaiement)
                        <x-statut :etat="$candidat->dernierPaiement->statut" />
                    @else
                        <span class="text-discret">Aucune autorisation</span>
                    @endif
                </x-tableau.cellule>
                <x-tableau.cellule>
                    @if ($candidat->occupationAutorisee())
                        <x-badge ton="succes">{{ $candidat->occupation_sans_recu ? 'Autorisée sans reçu' : 'Autorisée' }}</x-badge>
                    @else
                        <x-badge ton="alerte">Non autorisée</x-badge>
                        @foreach ($candidat->obstacles as $obstacle)<p class="mt-1 max-w-56 text-xs text-discret">{{ $obstacle }}</p>@endforeach
                    @endif
                </x-tableau.cellule>
                <x-tableau.cellule droite>
                    @if ($gerer)
                        @if ($candidat->occupationAutorisee())
                            <x-bouton taille="sm" variante="primaire" icone="bed-double" wire:click="preparer({{ $candidat->id }})">Attribuer un lit</x-bouton>
                        @else
                            <x-bouton taille="sm" icone="key-round" :disabled="count($candidat->obstacles) > 0"
                                x-on:click="$confirmer({ titre: 'Autoriser l’occupation ?', texte: 'Le candidat pourra recevoir un lit. Le paiement des frais d’internat n’en est pas une condition. La décision reste tracée.', libelle: 'Autoriser' }).then(() => $wire.autoriserOccupation({{ $candidat->id }}))">
                                Autoriser l’occupation
                            </x-bouton>
                        @endif
                    @endif
                </x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>

    @if ($dialogue === 'affectation' && $aLoger)
        <x-dialogue titre="Attribuer un lit" :description="$aLoger->numero_dossier.' — '.$aLoger->stagiaire->nom.' '.$aLoger->stagiaire->prenom">
            <form wire:submit="affecter" id="form-affectation" novalidate class="space-y-4">
                @if ($lits->isEmpty())
                    <x-alerte ton="alerte" titre="Aucun lit disponible">Tous les lits de cet internat sont occupés, hors service ou en maintenance.</x-alerte>
                @else
                    <x-liste libelle="Lit" wire:model="lit" vide="Choisir un lit…" requis>
                        @foreach ($lits as $chambre => $litsDeLaChambre)
                            <optgroup label="{{ $chambre }}">
                                @foreach ($litsDeLaChambre as $option)<option value="{{ $option->id }}">Lit {{ $option->numero }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </x-liste>
                @endif
                <x-saisie libelle="Date d’entrée" type="date" wire:model="date_debut" requis />
                <x-zone-texte libelle="Motif" wire:model="motif" rows="2" aide="Obligatoire pour une chambre Isolement ou Infirmerie ; facultatif sinon." />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-affectation" variante="primaire" cible="affecter" :disabled="$lits->isEmpty()">Attribuer le lit</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
