@php
    use App\Enums\CategorieCritere;
    use App\Enums\DeclencheurCritere;
    use App\Enums\NiveauFormation;
    use App\Enums\StatutCampagne;
    use App\Enums\StatutDemande;
    use App\Support\Affichage\Format;

    $deposes = $dossiersParStatut->sum();
    $niveaux = collect(NiveauFormation::options())->pluck('libelle', 'valeur');
@endphp
<div>
    <x-page.entete :titre="'Campagne '.$campagne->annee" :fil="[['Campagnes', route('campagnes')], [$campagne->annee.' — '.$campagne->internat->nom, null]]"
        :description="$campagne->internat->nom.' — '.$campagne->internat->etablissement->nom_efp">
        <x-slot:marque>
            <x-statut :etat="$campagne->statut" />
            @if ($campagne->estArchivee())<x-badge>Archivée</x-badge>@elseif ($campagne->estDeliberee())<x-badge ton="marine">Délibérée</x-badge>@endif
        </x-slot:marque>
        @if ($gerer && ! $campagne->estArchivee())
            <x-slot:actions>
                <x-bouton icone="pencil" wire:click="preparerModification">Modifier</x-bouton>
                @if ($preparation)
                    <x-bouton variante="primaire" icone="door-open" :disabled="count($blocages) > 0" cible="transiter"
                        x-on:click="$confirmer({ titre: 'Ouvrir la campagne ?', texte: 'Les stagiaires pourront déposer leur dossier. Les pièces exigées et la grille de critères ne seront plus modifiables directement.', libelle: 'Ouvrir la campagne' }).then(() => $wire.transiter('ouvrir'))">
                        Ouvrir
                    </x-bouton>
                    <x-bouton variante="danger-discret" icone="trash"
                        x-on:click="$confirmer({ titre: 'Supprimer cette campagne ?', texte: 'La campagne en préparation, ses pièces et sa grille seront supprimées. Cette action est définitive.', libelle: 'Supprimer', destructif: true }).then(() => $wire.supprimer())">
                        Supprimer
                    </x-bouton>
                @elseif ($campagne->statut === StatutCampagne::Ouverte)
                    <x-bouton variante="primaire" icone="door-closed"
                        x-on:click="$confirmer({ titre: 'Clôturer la campagne ?', texte: 'Plus aucun dossier ne pourra être déposé. Les dossiers reçus restent à instruire avant la délibération.', libelle: 'Clôturer' }).then(() => $wire.transiter('cloturer'))">
                        Clôturer
                    </x-bouton>
                @elseif ($campagne->estDeliberee())
                    <x-bouton icone="archive"
                        x-on:click="$confirmer({ titre: 'Archiver la campagne ?', texte: 'Elle restera consultable mais ne pourra plus être modifiée.', libelle: 'Archiver' }).then(() => $wire.transiter('archiver'))">
                        Archiver
                    </x-bouton>
                @endif
            </x-slot:actions>
        @endif
    </x-page.entete>

    @if (count($blocages) > 0)
        <x-alerte ton="alerte" titre="La campagne ne peut pas encore être ouverte" class="mb-6">
            <ul class="list-disc space-y-0.5 pl-4">@foreach ($blocages as $blocage)<li>{{ $blocage }}</li>@endforeach</ul>
        </x-alerte>
    @endif

    <x-onglets libelle="Sections de la campagne">
        <x-onglet :actif="$onglet === 'synthese'" wire:click="voir('synthese')">Vue d’ensemble</x-onglet>
        <x-onglet :actif="$onglet === 'pieces'" wire:click="voir('pieces')" :compteur="$typesPieces->count()">Pièces exigées</x-onglet>
        <x-onglet :actif="$onglet === 'grille'" wire:click="voir('grille')" :compteur="$criteres->count()">Grille de critères</x-onglet>
    </x-onglets>

    <div class="pt-5" wire:key="onglet-{{ $onglet }}">
        {{-- -------------------------------------------------------- Vue d'ensemble --}}
        @if ($onglet === 'synthese')
            <div class="grid items-start gap-6 xl:grid-cols-2">
                <x-carte>
                    <x-carte.entete titre="Paramètres de la campagne" />
                    <x-carte.corps>
                        <x-definitions>
                            <x-definition terme="Année de formation"><span class="chiffres">{{ $campagne->annee }}</span></x-definition>
                            <x-definition terme="Internat">{{ $campagne->internat->nom }} ({{ $campagne->internat->genre->libelle() }})</x-definition>
                            <x-definition terme="Ouverture du dépôt">{{ Format::dateLongue($campagne->date_ouverture) }}</x-definition>
                            <x-definition terme="Clôture du dépôt">{{ Format::dateLongue($campagne->date_cloture) }}</x-definition>
                            <x-definition terme="Places offertes"><span class="chiffres">{{ Format::nombre($campagne->nombre_places) }}</span> · {{ Format::nombre($litsEligibles) }} lit(s) éligible(s)</x-definition>
                            <x-definition terme="Frais d’internat">{{ $campagne->frais === null ? 'Non renseignés' : Format::montant($campagne->frais) }}</x-definition>
                            <x-definition terme="Version de la grille"><span class="chiffres">{{ $campagne->version_grille }}</span></x-definition>
                            <x-definition terme="Créée par">{{ $campagne->createur?->nomComplet() }}</x-definition>
                            <x-definition terme="Délibération">{{ $campagne->date_deliberation ? Format::dateHeure($campagne->date_deliberation) : '' }}</x-definition>
                            <x-definition terme="Publication des résultats">{{ $campagne->date_publication ? Format::dateHeure($campagne->date_publication) : '' }}</x-definition>
                        </x-definitions>
                    </x-carte.corps>
                </x-carte>

                <x-carte>
                    <x-carte.entete titre="Dossiers" :description="$deposes.' dossier(s) déposé(s)'">
                        @can('demandes.consulter')
                            <x-slot:actions><x-bouton taille="sm" :href="route('dossiers', ['campagne' => $campagne->id])" icone-fin="arrow-right">Voir les dossiers</x-bouton></x-slot:actions>
                        @endcan
                    </x-carte.entete>
                    @if ($deposes === 0)
                        <x-vide titre="Aucun dossier déposé" class="py-8!" />
                    @else
                        <ul>
                            @foreach (StatutDemande::cases() as $statut)
                                @if (($dossiersParStatut[$statut->value] ?? 0) > 0)
                                    <li class="flex items-center justify-between gap-3 border-b border-bord-doux px-5 py-2.5 last:border-0">
                                        <x-statut :etat="$statut" />
                                        <span class="chiffres text-base font-medium">{{ Format::nombre($dossiersParStatut[$statut->value]) }}</span>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                </x-carte>
            </div>
        @endif

        {{-- -------------------------------------------------------- Pièces exigées --}}
        @if ($onglet === 'pieces')
            <x-carte>
                <x-carte.entete titre="Pièces justificatives exigées"
                    description="Une pièce « conditionnelle » n’est demandée qu’aux candidats qui déclarent le critère auquel elle est rattachée.">
                    @if ($gerer && $preparation)
                        <x-slot:actions><x-bouton taille="sm" icone="plus" wire:click="preparerPiece">Ajouter une pièce</x-bouton></x-slot:actions>
                    @endif
                </x-carte.entete>
                @if ($typesPieces->isEmpty())
                    <x-vide titre="Aucune pièce définie">Ajoutez au moins une pièce avant d’ouvrir la campagne.</x-vide>
                @else
                    <ul>
                        @foreach ($typesPieces as $type)
                            <li class="flex flex-wrap items-center justify-between gap-3 border-b border-bord-doux px-5 py-3 last:border-0" wire:key="type-{{ $type->id }}">
                                <div class="min-w-0">
                                    <p class="text-base font-medium text-encre">{{ $type->libelle }}</p>
                                    <p class="text-sm text-discret">{{ $type->formats_acceptes }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <x-badge :ton="$type->obligatoire ? 'marine' : 'neutre'">{{ $type->obligatoire ? 'Exigée de tous' : 'Conditionnelle' }}</x-badge>
                                    @if ($gerer && $preparation)
                                        <x-bouton taille="icone" variante="discret" aria-label="Modifier {{ $type->libelle }}" wire:click="preparerPiece({{ $type->id }})"><x-icone nom="pencil" /></x-bouton>
                                        <x-bouton taille="icone" variante="discret" aria-label="Supprimer {{ $type->libelle }}"
                                            x-on:click="$confirmer({ titre: 'Supprimer cette pièce ?', texte: 'Elle ne sera plus demandée aux candidats.', libelle: 'Supprimer', destructif: true }).then(() => $wire.supprimerPiece({{ $type->id }}))">
                                            <x-icone nom="trash" />
                                        </x-bouton>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-carte>
        @endif

        {{-- ----------------------------------------------------- Grille de critères --}}
        @if ($onglet === 'grille')
            @if ($maximum > $plafond)
                <x-alerte ton="danger" class="mb-4" titre="Barème dépassé">
                    La grille permet d’atteindre {{ $maximum }} points alors que le score est plafonné à {{ $plafond }}. Réduisez les points de certains critères.
                </x-alerte>
            @endif
            <x-tableau legende="Grille de notation de la campagne" :lignes="$criteres" :plafonne="false" vide="Grille vide"
                vide-description="La grille de critères est saisie par l’administrateur à partir de la grille nationale de référence.">
                <x-slot:barre>
                    <div class="min-w-0 flex-1">
                        <p class="text-md font-semibold text-encre">Grille de notation</p>
                        <p class="text-sm text-discret">Score maximal atteignable : {{ $maximum }} / {{ $plafond }} points (un seul niveau de formation compte par candidat).</p>
                    </div>
                    @if ($gererCriteres)
                        <x-bouton taille="sm" icone="refresh-cw" wire:click="appliquerReference" cible="appliquerReference">Reprendre la grille de référence</x-bouton>
                        <x-bouton taille="sm" variante="primaire" icone="plus" wire:click="preparerCritere">Ajouter un critère</x-bouton>
                    @endif
                </x-slot:barre>
                <x-slot:entete>
                    <x-tableau.colonne>Catégorie</x-tableau.colonne>
                    <x-tableau.colonne>Condition</x-tableau.colonne>
                    <x-tableau.colonne>Donnée du dossier</x-tableau.colonne>
                    <x-tableau.colonne>Pièce justificative</x-tableau.colonne>
                    <x-tableau.colonne droite>Points</x-tableau.colonne>
                    <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
                </x-slot:entete>
                @foreach ($criteres as $ligneCritere)
                    <x-tableau.ligne wire:key="critere-{{ $ligneCritere->id }}">
                        <x-tableau.cellule><x-badge>{{ $ligneCritere->categorie->libelle() }}</x-badge></x-tableau.cellule>
                        <x-tableau.cellule class="font-medium">{{ $ligneCritere->condition_texte }}</x-tableau.cellule>
                        <x-tableau.cellule class="text-encre-2">
                            {{ $ligneCritere->declencheur->libelle() }}@if ($ligneCritere->valeur_attendue) : {{ $niveaux[$ligneCritere->valeur_attendue] ?? $ligneCritere->valeur_attendue }}@endif
                        </x-tableau.cellule>
                        <x-tableau.cellule class="text-encre-2">{{ $ligneCritere->typePiece?->libelle ?? '—' }}</x-tableau.cellule>
                        <x-tableau.cellule droite class="font-medium">{{ $ligneCritere->points }}</x-tableau.cellule>
                        <x-tableau.cellule droite>
                            @if ($gererCriteres)
                                <x-bouton taille="icone" variante="discret" aria-label="Modifier le critère" wire:click="preparerCritere({{ $ligneCritere->id }})"><x-icone nom="pencil" /></x-bouton>
                                <x-bouton taille="icone" variante="discret" aria-label="Supprimer le critère"
                                    x-on:click="$confirmer({ titre: 'Supprimer ce critère ?', texte: 'Il ne rapportera plus de points aux candidats de cette campagne.', libelle: 'Supprimer', destructif: true }).then(() => $wire.supprimerCritere({{ $ligneCritere->id }}))">
                                    <x-icone nom="trash" />
                                </x-bouton>
                            @endif
                        </x-tableau.cellule>
                    </x-tableau.ligne>
                @endforeach
            </x-tableau>
        @endif

    </div>

    {{-- ------------------------------------------------------------------ Fenêtres --}}
    @if ($dialogue === 'parametres')
        <x-dialogue titre="Modifier la campagne" :description="$preparation ? null : 'Après l’ouverture, seuls la date de clôture et le nombre de places restent ajustables.'">
            <form wire:submit="modifier" id="form-parametres" novalidate class="grid gap-4 sm:grid-cols-2">
                <x-saisie libelle="Année de formation" wire:model="parametres.annee" requis :disabled="! $preparation" />
                <x-saisie libelle="Frais d’internat (DH)" wire:model="parametres.frais" type="number" min="0" step="0.01" :disabled="! $preparation" />
                <x-saisie libelle="Ouverture du dépôt" wire:model="parametres.date_ouverture" type="date" requis :disabled="! $preparation" />
                <x-saisie libelle="Clôture du dépôt" wire:model="parametres.date_cloture" type="date" requis />
                <x-saisie libelle="Places offertes" wire:model="parametres.nombre_places" type="number" min="1" requis
                    :aide="$litsEligibles.' lit(s) éligible(s) dans le référentiel de l’internat.'" />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-parametres" variante="primaire" cible="modifier">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'piece')
        <x-dialogue :titre="$ligne ? 'Modifier la pièce' : 'Ajouter une pièce exigée'">
            <form wire:submit="enregistrerPiece" id="form-piece" novalidate class="space-y-4">
                <x-saisie libelle="Libellé" wire:model="piece.libelle" requis autofocus placeholder="Copie de la CIN" />
                <x-saisie libelle="Formats acceptés" wire:model="piece.formats_acceptes" requis aide="Formats et taille maximale, par exemple : PDF, JPG, 5 Mo." />
                <x-case wire:model="piece.obligatoire" libelle="Exigée de tous les candidats" />
                <p class="text-sm text-discret">Décochée, la pièce n’est demandée qu’aux candidats qui déclarent le critère auquel elle est rattachée.</p>
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-piece" variante="primaire" cible="enregistrerPiece">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

    @if ($dialogue === 'critere')
        <x-dialogue :titre="$ligne ? 'Modifier le critère' : 'Ajouter un critère'"
            description="Un critère rapporte ses points si la donnée du dossier est vraie et si la pièce rattachée est acceptée.">
            <form wire:submit="enregistrerCritere" id="form-critere" novalidate class="grid gap-4 sm:grid-cols-2">
                <x-liste libelle="Catégorie" wire:model="critere.categorie" :options="CategorieCritere::options()" vide="Choisir…" requis />
                <x-liste libelle="Donnée du dossier" wire:model.live="critere.declencheur" :options="DeclencheurCritere::options()" vide="Choisir…" requis />
                @if ($critere['declencheur'] === DeclencheurCritere::NiveauFormation->value)
                    <x-liste libelle="Niveau de formation visé" wire:model="critere.valeur_attendue" :options="NiveauFormation::options()" vide="Choisir…" requis class="sm:col-span-2" />
                @endif
                <x-saisie libelle="Condition" wire:model="critere.condition" requis class="sm:col-span-2" aide="Texte affiché au stagiaire et au gestionnaire." />
                <x-saisie libelle="Points" wire:model="critere.points" type="number" min="0" max="80" requis />
                <x-liste libelle="Pièce justificative" wire:model="critere.type_piece_id" :options="$typesPieces->pluck('libelle', 'id')" vide="Aucune" />
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-critere" variante="primaire" cible="enregistrerCritere">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif

</div>
