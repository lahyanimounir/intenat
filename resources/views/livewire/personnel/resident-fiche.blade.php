@php
    use App\Enums\StatutDemande;
    use App\Support\Affichage\Format;

    $nom = $stagiaire->nom.' '.$stagiaire->prenom;
    $chambre = $affectation->lit->chambre;
    $desiste = $affectation->demande->statut === StatutDemande::Desiste;
@endphp
<div>
    <x-page.entete :titre="$nom" :fil="[['Résidents', route('hebergement.residents')], [$nom, null]]"
        :description="$affectation->demande->campagne->internat->nom.' — chambre '.$chambre->numero.', lit '.$affectation->lit->numero">
        <x-slot:marque>
            @if (! $affectation->estActive())
                <x-badge>Séjour clos</x-badge>
            @elseif ($arrivee)
                <x-badge ton="succes" point>Présent</x-badge>
            @else
                <x-badge ton="alerte" point>Arrivée attendue</x-badge>
            @endif
        </x-slot:marque>
        <x-slot:actions>
            <x-bouton icone="download" :href="route('hebergement.residents.attestation', $affectation)" :navigation="false">Attestation</x-bouton>
            @if ($gerer)
                @if (! $arrivee)
                    <x-bouton variante="primaire" icone="door-open" cible="enregistrerArrivee"
                        x-on:click="$confirmer({ titre: 'Enregistrer l’arrivée ?', texte: 'Vous attestez que le résident a pris possession de son lit aujourd’hui.', libelle: 'Enregistrer l’arrivée' }).then(() => $wire.enregistrerArrivee())">
                        Enregistrer l’arrivée
                    </x-bouton>
                @endif
                <x-bouton icone="arrow-right-left" wire:click="preparer('lit')">Changer de lit</x-bouton>
                <x-bouton variante="danger-discret" icone="door-closed" wire:click="preparer('fin')">Clore le séjour</x-bouton>
            @endif
        </x-slot:actions>
    </x-page.entete>

    @if ($desiste && $affectation->estActive())
        <x-alerte ton="alerte" titre="Ce résident s’est désisté" class="mb-5">
            Son lit reste attribué tant que le séjour n’est pas clos : enregistrez son départ pour libérer la place.
        </x-alerte>
    @endif

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="space-y-6">
            <x-carte>
                <x-carte.entete titre="Séjour">
                    <x-slot:actions>
                        <x-bouton taille="sm" variante="discret" :href="route('dossiers.fiche', $affectation->demande_id)" icone-fin="arrow-right">Dossier {{ $affectation->demande->numero_dossier }}</x-bouton>
                    </x-slot:actions>
                </x-carte.entete>
                <x-carte.corps>
                    <x-definitions :colonnes="3">
                        <x-definition terme="CEF"><span class="chiffres">{{ $stagiaire->cef }}</span></x-definition>
                        <x-definition terme="Filière">{{ $stagiaire->nom_filiere }}</x-definition>
                        <x-definition terme="Établissement">{{ $stagiaire->etablissement?->nom_efp }}</x-definition>
                        <x-definition terme="Téléphone"><span class="chiffres">{{ $stagiaire->tel }}</span></x-definition>
                        <x-definition terme="E-mail"><span class="break-all">{{ $stagiaire->email }}</span></x-definition>
                        <x-definition terme="Ville">{{ $stagiaire->ville }}</x-definition>
                        <x-definition terme="Bâtiment">{{ $chambre->batiment->code }} — {{ $chambre->batiment->nom }}</x-definition>
                        <x-definition terme="Chambre"><span class="chiffres">{{ $chambre->numero }}</span> · étage {{ $chambre->etage }}</x-definition>
                        <x-definition terme="Lit"><span class="chiffres">{{ $affectation->lit->numero }}</span></x-definition>
                        <x-definition terme="Entrée prévue le">{{ Format::date($affectation->date_debut) }}</x-definition>
                        <x-definition terme="Arrivée">{{ $arrivee ? Format::dateHeure($arrivee->date_mouvement) : '' }}</x-definition>
                        <x-definition terme="Fin du séjour">{{ $affectation->date_fin ? Format::date($affectation->date_fin) : '' }}</x-definition>
                        @if ($affectation->motif_exceptionnel)
                            <x-definition terme="Motif de l’attribution exceptionnelle" class="sm:col-span-2 lg:col-span-3">{{ $affectation->motif_exceptionnel }}</x-definition>
                        @endif
                    </x-definitions>
                </x-carte.corps>
            </x-carte>

        </div>

        <div class="space-y-6">
            <x-carte>
                <x-carte.entete titre="Suivi" />
                <x-carte.corps>
                    <x-definitions>
                        <x-definition terme="Sorties demandées"><span class="chiffres">{{ $affectation->sorties->count() }}</span></x-definition>
                        <x-definition terme="Absences"><span class="chiffres">{{ $affectation->absences->count() }}</span></x-definition>
                        <x-definition terme="Absences non autorisées">
                            <span @class(['chiffres', 'font-medium text-danger' => $affectation->absences->where('autorisee', false)->count() > 0])>{{ $affectation->absences->where('autorisee', false)->count() }}</span>
                        </x-definition>
                        <x-definition terme="Incidents"><span class="chiffres">{{ $affectation->incidents->count() }}</span></x-definition>
                        @foreach ($affectation->demande->paiements->take(3) as $paiement)
                            <x-definition terme="Frais d’internat"><x-statut :etat="$paiement->statut" /></x-definition>
                        @endforeach
                    </x-definitions>
                </x-carte.corps>
            </x-carte>

            <x-carte>
                <x-carte.entete titre="Mouvements" description="Historique du séjour." />
                @if ($affectation->mouvements->isEmpty())
                    <x-vide titre="Aucun mouvement enregistré" class="py-8!" />
                @else
                    <ul>
                        @foreach ($affectation->mouvements->sortByDesc('date_mouvement') as $mouvement)
                            <li class="border-b border-bord-doux px-5 py-2.5 last:border-0">
                                <p class="text-base text-encre"><span class="font-medium">{{ $mouvement->type->libelle() }}</span> <span class="text-discret">· {{ Format::dateHeure($mouvement->date_mouvement) }}</span></p>
                                <p class="text-sm text-discret">{{ $mouvement->auteur?->nomComplet() }}@if ($mouvement->motif) · {{ $mouvement->motif }}@endif</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-carte>

            <x-carte>
                <x-carte.entete titre="Lits occupés" description="Les périodes passées sont conservées." />
                <ul>
                    @foreach ($historiqueLits as $periode)
                        <li class="flex items-center justify-between gap-3 border-b border-bord-doux px-5 py-2.5 last:border-0">
                            <span class="chiffres text-base">Chambre {{ $periode->lit->chambre->numero }}, lit {{ $periode->lit->numero }}</span>
                            <span class="chiffres text-sm text-discret">{{ Format::date($periode->date_debut) }} → {{ $periode->date_fin ? Format::date($periode->date_fin) : 'en cours' }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-carte>
        </div>
    </div>

    {{-- ------------------------------------------------------------------ Fenêtres --}}
    @if ($dialogue === 'lit')
        <x-dialogue titre="Changer de lit" description="La période en cours est close et conservée ; une nouvelle période s’ouvre sur le lit choisi.">
            <form wire:submit="changerDeLit" id="form-changement" novalidate class="space-y-4">
                @if ($litsLibres->isEmpty())
                    <x-alerte ton="alerte" titre="Aucun lit disponible">Tous les lits de cet internat sont occupés, hors service ou en maintenance.</x-alerte>
                @else
                    <x-liste libelle="Nouveau lit" wire:model="lit" vide="Choisir un lit…" requis>
                        @foreach ($litsLibres as $libelleChambre => $litsDeLaChambre)
                            <optgroup label="{{ $libelleChambre }}">
                                @foreach ($litsDeLaChambre as $option)<option value="{{ $option->id }}">Lit {{ $option->numero }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </x-liste>
                @endif
                <x-zone-texte libelle="Motif du changement" wire:model="motif" requis />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-changement" variante="primaire" cible="changerDeLit" :disabled="$litsLibres->isEmpty()">Changer de lit</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'fin')
        <x-dialogue titre="Clore le séjour" description="Le lit est libéré et le chef de pôle est alerté de la place libre. L’historique du séjour est conservé.">
            <form wire:submit="cloturer" id="form-fin" novalidate class="space-y-4">
                <x-liste libelle="Type de fin de séjour" wire:model="type" :options="$fins" requis />
                <x-zone-texte libelle="Motif" wire:model="motif" rows="4" requis />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-fin" variante="danger" cible="cloturer">Clore le séjour</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

</div>
