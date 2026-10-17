@php
    use App\Enums\StatutDemande;
    use App\Enums\StatutInscription;
    use App\Enums\StatutPiece;
    use App\Support\Affichage\Format;

    $inscription = $stagiaire->statut_inscription;
    $pret = $nonControlees === 0 && $aRemplacer === 0 && $acceptees === $requises->count() && $inscription === StatutInscription::Verifiee;
    $admis = in_array($demande->statut, [StatutDemande::Admis, StatutDemande::Confirme], true);
@endphp
<div>
    <x-page.entete :titre="'Dossier '.$demande->numero_dossier" :fil="[['Dossiers', route('dossiers')], [$demande->numero_dossier, null]]"
        :description="$stagiaire->nom.' '.$stagiaire->prenom.' — '.$demande->campagne->internat->nom.', campagne '.$demande->campagne->annee">
        <x-slot:marque><x-statut :etat="$demande->statut" /></x-slot:marque>
        <x-slot:actions>
            <x-bouton icone="download" :href="route('dossiers.accuse', $demande)" :navigation="false">Accusé de réception</x-bouton>
            @if ($gererHebergement && $admis && ! $demande->dossier_physique_depose)
                <x-bouton icone="file-check-corner" cible="recevoirDossierPhysique"
                    x-on:click="$confirmer({ titre: 'Dossier physique reçu ?', texte: 'Vous attestez avoir reçu et contrôlé le dossier papier de ce candidat.', libelle: 'Enregistrer la réception' }).then(() => $wire.recevoirDossierPhysique())">
                    Dossier physique reçu
                </x-bouton>
            @endif
            @if ($gererHebergement && $demande->statut === StatutDemande::Confirme && ! $demande->occupationAutorisee())
                <x-bouton variante="primaire" icone="key-round" :disabled="count($obstacles) > 0" cible="autoriserOccupation"
                    x-on:click="$confirmer({ titre: 'Autoriser l’occupation ?', texte: 'Le candidat pourra recevoir un lit. Cette décision est distincte du paiement de l’hébergement et reste tracée.', libelle: 'Autoriser' }).then(() => $wire.autoriserOccupation())">
                    Autoriser l’occupation
                </x-bouton>
            @endif
        </x-slot:actions>
    </x-page.entete>

    @if (count($obstacles) > 0)
        <x-alerte ton="alerte" titre="L’occupation ne peut pas encore être autorisée" class="mb-5">
            <ul class="list-disc space-y-0.5 pl-4">@foreach ($obstacles as $obstacle)<li>{{ $obstacle }}</li>@endforeach</ul>
        </x-alerte>
    @endif

    @if ($demande->statut === StatutDemande::Recu && $inscription !== StatutInscription::Verifiee)
        <x-alerte ton="alerte" class="mb-5" titre="Inscription en formation non vérifiée">
            L’inscription de ce stagiaire est « {{ mb_strtolower($inscription->libelle()) }} ». Le dossier ne peut pas être déclaré complet — ni participer à la
            sélection — tant qu’elle n’a pas été vérifiée.
            @can('inscriptions.verifier')
                <x-slot:action><x-bouton taille="sm" :href="route('inscriptions.fiche', $stagiaire)">Vérifier l’inscription</x-bouton></x-slot:action>
            @endcan
        </x-alerte>
    @endif

    @if ($demande->statut === StatutDemande::RetourRectification && $demande->motif_rectification)
        <x-alerte ton="alerte" titre="Renvoyé au stagiaire pour rectification" class="mb-5">{{ $demande->motif_rectification }}</x-alerte>
    @elseif ($demande->statut === StatutDemande::Rejete && $demande->motif_rejet)
        <x-alerte ton="danger" titre="Dossier rejeté" class="mb-5">{{ $demande->motif_rejet }}</x-alerte>
    @endif

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div class="space-y-6">
            <x-carte>
                <x-carte.entete titre="Stagiaire" description="Informations déclarées par le stagiaire à son inscription.">
                    <x-slot:actions><x-statut :etat="$inscription" /></x-slot:actions>
                </x-carte.entete>
                <x-carte.corps>
                    <x-definitions>
                        <x-definition terme="Nom et prénom">{{ $stagiaire->nom }} {{ $stagiaire->prenom }}</x-definition>
                        <x-definition terme="CEF"><span class="chiffres">{{ $stagiaire->cef }}</span></x-definition>
                        <x-definition terme="CNI">{{ $stagiaire->cni }}</x-definition>
                        <x-definition terme="Genre">{{ $stagiaire->genre?->libelle() }}</x-definition>
                        <x-definition terme="Filière">{{ $stagiaire->nom_filiere }}</x-definition>
                        <x-definition terme="Niveau de formation">{{ $stagiaire->niveau_formation?->libelle() }}</x-definition>
                        <x-definition terme="Établissement">{{ $stagiaire->etablissement ? $stagiaire->etablissement->nom_efp.' ('.$stagiaire->etablissement->code_efp.')' : '' }}</x-definition>
                        <x-definition terme="Téléphone"><span class="chiffres">{{ $stagiaire->tel }}</span></x-definition>
                        <x-definition terme="E-mail">{{ $stagiaire->email }}</x-definition>
                        <x-definition terme="Ville">{{ $stagiaire->ville }}</x-definition>
                    </x-definitions>
                </x-carte.corps>
            </x-carte>

            <x-carte>
                <x-carte.entete titre="Situation déclarée" description="Chaque déclaration doit être appuyée par sa pièce justificative." />
                <x-carte.corps>
                    <x-definitions :colonnes="1">
                        <x-definition terme="Adresse">{{ $demande->adresse_complete }}</x-definition>
                        <x-definition terme="Milieu rural, plus de 10 km ou filière absente de sa ville"><x-oui-non :valeur="$demande->eloigne_ou_rural" oui="Déclaré" non="Non déclaré" /></x-definition>
                        <x-definition terme="Stagiaire démuni"><x-oui-non :valeur="$demande->demuni" oui="Déclaré" non="Non déclaré" /></x-definition>
                        <x-definition terme="Orphelin ou parents divorcés"><x-oui-non :valeur="$demande->orphelin_ou_divorce" oui="Déclaré" non="Non déclaré" /></x-definition>
                        <x-definition terme="Besoins spécifiques"><x-oui-non :valeur="$demande->besoins_specifiques" oui="Déclaré" non="Non déclaré" /></x-definition>
                        @if ($demande->precisions_besoins)<x-definition terme="Précisions sur les besoins">{{ $demande->precisions_besoins }}</x-definition>@endif
                    </x-definitions>
                </x-carte.corps>
            </x-carte>

            @if ($voirClassement)
                <x-carte>
                    <x-carte.entete titre="Sélection">
                        <x-slot:actions>
                            <x-bouton taille="sm" :href="url('/admissions/classement?campagne='.$demande->campagne_id.'&dossier='.$demande->id)">Détail du score</x-bouton>
                        </x-slot:actions>
                    </x-carte.entete>
                    <x-carte.corps>
                        <x-definitions :colonnes="3">
                            <x-definition terme="Score">{{ $demande->score === null ? '' : $demande->score.' / '.config('internat.score.bareme_maximum') }}</x-definition>
                            <x-definition terme="Rang">{{ $demande->rang }}</x-definition>
                            <x-definition terme="Liste">{{ $demande->liste?->libelle() }}</x-definition>
                        </x-definitions>
                    </x-carte.corps>
                </x-carte>
            @endif

            <x-carte>
                <x-carte.entete titre="Historique des décisions" />
                <ol class="px-5 py-4">
                    @forelse ($historique as $etape)
                        <li class="relative flex gap-3 pb-4 last:pb-0">
                            @if (! $loop->last)<span aria-hidden="true" class="absolute top-3 left-[5px] h-full w-px bg-bord"></span>@endif
                            <span aria-hidden="true" class="relative mt-1.5 size-[11px] shrink-0 rounded-full border-2 border-marine bg-surface"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-base text-encre">
                                    @if ($etape->ancien_statut)<span class="text-discret">{{ StatutDemande::from($etape->ancien_statut)->libelle() }} → </span>@endif
                                    <span class="font-medium">{{ StatutDemande::from($etape->nouveau_statut)->libelle() }}</span>
                                </p>
                                <p class="text-sm text-discret">
                                    {{ $etape->automatique ? 'Application' : ($etape->utilisateur?->nomComplet() ?? 'Stagiaire') }} · {{ Format::dateHeure($etape->date_heure) }}
                                </p>
                                @if ($etape->motif)<p class="mt-1 text-sm text-encre-2">{{ $etape->motif }}</p>@endif
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-discret">Aucune décision enregistrée.</li>
                    @endforelse
                </ol>
            </x-carte>
        </div>

        <div class="space-y-6 xl:sticky xl:top-20">
            <x-carte>
                <x-carte.entete titre="Pièces justificatives" :description="$acceptees.' pièce(s) requise(s) acceptée(s) sur '.$requises->count()">
                    @if ($nonControlees > 0)
                        <x-slot:actions><x-badge ton="alerte">{{ $nonControlees }} à contrôler</x-badge></x-slot:actions>
                    @endif
                </x-carte.entete>
                <ul>
                    @foreach ($pieces as $ligne)
                        @php
                            $type = $ligne['type'];
                            $piece = $ligne['piece'];
                        @endphp
                        <li @class(['border-b border-bord-doux px-5 py-3.5 last:border-0', 'opacity-60' => ! $ligne['requise'] && ! $piece]) wire:key="piece-{{ $type->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-base font-medium text-encre">{{ $type->libelle }}</p>
                                    <p class="text-sm text-discret">
                                        {{ $type->obligatoire ? 'Exigée de tous' : ($ligne['requise'] ? 'Exigée : '.$ligne['raison'] : 'Non requise pour ce dossier') }}
                                        @if ($piece) · {{ Format::taille($piece->taille) }} · déposée le {{ Format::date($piece->date_depot) }}@endif
                                    </p>
                                </div>
                                @if ($piece)
                                    <x-statut :etat="$piece->statut" />
                                @elseif ($ligne['requise'])
                                    <x-badge ton="danger">Manquante</x-badge>
                                @endif
                            </div>
                            @if ($piece?->commentaire)<p class="mt-2 rounded-sm bg-alerte-fond px-2.5 py-1.5 text-sm text-encre-2">{{ $piece->commentaire }}</p>@endif
                            @if ($piece)
                                <div class="mt-2.5 flex flex-wrap gap-2">
                                    <x-bouton taille="sm" icone="eye" wire:click="voirPiece({{ $piece->id }})">Consulter</x-bouton>
                                    @if ($instruire)
                                        <x-bouton taille="sm" icone="check" :variante="$piece->statut === StatutPiece::Acceptee ? 'secondaire' : 'primaire'"
                                            :disabled="$piece->statut === StatutPiece::Acceptee" wire:click="accepterPiece({{ $piece->id }})" cible="accepterPiece({{ $piece->id }})">Accepter</x-bouton>
                                        <x-bouton taille="sm" variante="danger-discret" icone="rotate-ccw"
                                            x-on:click="$motif({ titre: 'Demander le remplacement de la pièce', description: 'Indiquez au stagiaire ce qui ne convient pas : il verra ce commentaire à côté de la pièce.', libelle: 'Commentaire pour le stagiaire', libelleAction: 'Marquer à remplacer', minimum: 3 }).then((commentaire) => $wire.demanderRemplacement({{ $piece->id }}, commentaire))">
                                            À remplacer
                                        </x-bouton>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-carte>

            @if ($instruire || $rejetPossible)
                <x-carte>
                    <x-carte.entete titre="Décision d’instruction" description="La décision est tracée (auteur, date, motif) et notifiée au stagiaire." />
                    <x-carte.corps class="space-y-3">
                        @if ($instruire && ! $pret)
                            <x-alerte ton="neutre">
                                @if ($aRemplacer > 0)
                                    Une pièce est à remplacer : renvoyez le dossier au stagiaire pour rectification.
                                @elseif ($inscription !== StatutInscription::Verifiee && $nonControlees === 0)
                                    L’inscription en formation du stagiaire doit être vérifiée avant de déclarer le dossier complet.
                                @else
                                    Contrôlez toutes les pièces déposées pour pouvoir déclarer le dossier complet.
                                @endif
                            </x-alerte>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            @if ($instruire)
                                <x-bouton variante="primaire" icone="file-check-corner" :disabled="! $pret" cible="declarerComplet"
                                    x-on:click="$confirmer({ titre: 'Déclarer le dossier complet ?', texte: 'Le score sera calculé et le dossier participera à la sélection.\nCette décision est définitive : un dossier complet ne peut plus être modifié ni renvoyé.', libelle: 'Déclarer complet' }).then(() => $wire.declarerComplet())">
                                    Déclarer complet
                                </x-bouton>
                                <x-bouton icone="rotate-ccw"
                                    x-on:click="$motif({ titre: 'Renvoyer le dossier pour rectification', description: 'Le stagiaire pourra corriger les pièces ou les informations demandées, puis soumettre de nouveau.', libelle: 'Ce que le stagiaire doit corriger', libelleAction: 'Renvoyer au stagiaire', minimum: 10 }).then((motif) => $wire.renvoyer(motif))">
                                    Renvoyer pour rectification
                                </x-bouton>
                            @endif
                            <x-bouton variante="danger-discret" icone="x"
                                x-on:click="$motif({ titre: 'Rejeter le dossier', description: 'Le rejet est définitif et son motif est communiqué au stagiaire.', libelle: 'Motif du rejet', libelleAction: 'Rejeter le dossier', minimum: 10, destructif: true }).then((motif) => $wire.rejeter(motif))">
                                Rejeter
                            </x-bouton>
                        </div>
                    </x-carte.corps>
                </x-carte>
            @endif

            @if ($demande->paiements->isNotEmpty() || $demande->affectationActive || $admis)
                <x-carte>
                    <x-carte.entete titre="Suites de l’admission" />
                    <x-carte.corps>
                        <x-definitions>
                            <x-definition terme="Confirmation">{{ $demande->date_confirmation ? Format::dateHeure($demande->date_confirmation) : '' }}</x-definition>
                            <x-definition terme="Dossier physique"><x-oui-non :valeur="$demande->dossier_physique_depose" oui="Reçu" non="Attendu" /></x-definition>
                            <x-definition terme="Occupation">{{ $demande->occupationAutorisee() ? ($demande->occupation_sans_recu ? 'Autorisée sans reçu' : 'Autorisée') : 'Non autorisée' }}</x-definition>
                            <x-definition terme="Lit attribué">
                                @if ($demande->affectationActive)
                                    <a class="text-bleu hover:underline" wire:navigate href="{{ url('/hebergement/residents/'.$demande->affectationActive->id) }}">Chambre {{ $demande->affectationActive->lit->chambre->numero }}, lit {{ $demande->affectationActive->lit->numero }}</a>
                                @endif
                            </x-definition>
                            @foreach ($demande->paiements as $paiement)
                                <x-definition terme="Paiement de l’hébergement"><x-statut :etat="$paiement->statut" /></x-definition>
                            @endforeach
                        </x-definitions>
                    </x-carte.corps>
                </x-carte>
            @endif
        </div>
    </div>

    @if ($dialogue === 'piece' && $pieceAffichee)
        <x-apercu :chemin="route('dossiers.piece', $pieceAffichee)" :titre="$pieceAffichee->typePiece->libelle" :nom="$pieceAffichee->nom_original" />
    @endif
</div>
