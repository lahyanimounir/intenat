@php
    use App\Enums\RaisonDesistement;
    use App\Enums\StatutDemande;
    use App\Enums\StatutInscription;
    use App\Enums\StatutPiece;
    use App\Support\Affichage\Format;

    $inscription = $stagiaire->statut_inscription;
@endphp
<div>
    <x-page.entete titre="Mon dossier"
        :description="$demande ? ($demande->campagne->internat->nom.' — campagne '.$demande->campagne->annee.' · suivi de votre candidature et de votre admission') : 'Votre candidature se dépose désormais depuis « Mon inscription ».'">
        @if ($demande)<x-slot:marque><x-statut :etat="$demande->statut" /></x-slot:marque> @endif
    </x-page.entete>

    {{-- L'inscription en formation conditionne le dépôt et la sélection : son état est rappelé tant qu'elle n'est pas vérifiée. --}}
    @if ($inscription !== StatutInscription::Verifiee && (! $demande || $demande->estModifiableParLeStagiaire() || $demande->statut === StatutDemande::Recu))
        <x-alerte :ton="$inscription === StatutInscription::Soumise ? 'info' : 'alerte'" class="mb-5"
            :titre="match ($inscription) {
                StatutInscription::Soumise => 'Votre inscription en formation est en cours de vérification',
                StatutInscription::ACorriger => 'Une correction est demandée sur votre inscription en formation',
                StatutInscription::Rejetee => 'Votre inscription en formation a été rejetée',
                default => 'Votre inscription en formation n’a pas encore été soumise à la vérification',
            }">
            @if ($inscription === StatutInscription::Soumise)
                Vous pouvez préparer et déposer votre dossier. Il ne participera à la sélection qu’une fois votre inscription vérifiée par votre établissement.
            @else
                Votre dossier ne peut pas être déposé tant que votre inscription n’a pas été soumise à la vérification, avec son justificatif.
            @endif
            <x-slot:action><x-bouton taille="sm" :href="route('stagiaire.inscription')">Mon inscription</x-bouton></x-slot:action>
        </x-alerte>
    @endif

    @if ($demande === null)
        {{-- ------------------------------------------------------ Aucun dossier --}}
        <x-carte>
            @if ($campagneOuverte)
                <x-vide titre="Une campagne est ouverte pour votre internat">
                    {{ $campagneOuverte->internat->nom }} — dépôt des dossiers jusqu’au <strong>{{ Format::dateLongue($campagneOuverte->date_cloture) }}</strong>.
                    <x-slot:action><x-bouton variante="primaire" wire:click="ouvrir" cible="ouvrir">Commencer ma demande</x-bouton></x-slot:action>
                </x-vide>
            @else
                <x-vide titre="Aucune campagne ouverte pour l’instant">
                    Le dépôt des demandes n’est pas ouvert pour votre internat. Vous serez prévenu dès l’ouverture d’une campagne.
                </x-vide>
            @endif
        </x-carte>
    @elseif ($modifiable)
        {{-- --------------------------------------------------- Parcours de dépôt --}}
        @php $rectification = $demande->statut === StatutDemande::RetourRectification; @endphp

        @if ($rectification && $demande->motif_rectification)
            <x-alerte ton="alerte" titre="Votre dossier doit être rectifié" class="mb-5">{{ $demande->motif_rectification }}</x-alerte>
        @endif

        <x-carte class="mb-6 px-5 py-4">
            <x-etapes :etapes="['Mes informations', 'Ma situation', 'Pièces justificatives', 'Vérification et envoi']" :courante="$etape" action="allerA" />
        </x-carte>

        @if ($etape === 0)
            <x-carte>
                <x-carte.entete titre="Mes informations" description="Celles que vous avez déclarées à votre inscription. Elles ne sont pas à ressaisir ici." />
                <x-carte.corps>
                    <x-definitions>
                        <x-definition terme="Nom et prénom">{{ $stagiaire->nom }} {{ $stagiaire->prenom }}</x-definition>
                        <x-definition terme="CEF"><span class="chiffres">{{ $stagiaire->cef }}</span></x-definition>
                        <x-definition terme="CNI">{{ $stagiaire->cni ?? 'Non renseignée' }}</x-definition>
                        <x-definition terme="E-mail">{{ $stagiaire->email }}</x-definition>
                        <x-definition terme="Téléphone">{{ $stagiaire->tel }}</x-definition>
                        <x-definition terme="Ville">{{ $stagiaire->ville }}</x-definition>
                        <x-definition terme="Établissement">{{ $stagiaire->etablissement?->nom_efp }}</x-definition>
                        <x-definition terme="Filière">{{ $stagiaire->nom_filiere }}</x-definition>
                        <x-definition terme="Genre">{{ $stagiaire->genre?->libelle() }}</x-definition>
                        <x-definition terme="Niveau de formation">{{ $stagiaire->niveau_formation?->libelle() }}</x-definition>
                        <x-definition terme="Inscription en formation"><x-statut :etat="$inscription" /></x-definition>
                    </x-definitions>
                    <p class="mt-4 text-sm text-discret">
                        Une erreur ? Vos coordonnées se corrigent à tout moment dans
                        <a href="{{ route('stagiaire.inscription', ['etape' => 'identite']) }}" wire:navigate class="text-bleu hover:underline">Mon inscription</a>.
                        L’identité et la formation ne se modifient plus une fois soumises à la vérification.
                    </p>
                </x-carte.corps>
            </x-carte>
        @endif

        @if ($etape === 1)
            <x-carte>
                <x-carte.entete titre="Ma situation" description="Ces déclarations servent au calcul de votre score. Chacune doit être appuyée par une pièce justificative, demandée à l’étape suivante." />
                <x-carte.corps class="space-y-5">
                    <x-zone-texte libelle="Adresse complète" wire:model="situation.adresse_complete" rows="2" placeholder="Numéro, rue, quartier, ville" requis />

                    <fieldset class="space-y-2.5">
                        <legend class="mb-1 text-sm font-medium">Déclarations</legend>
                        <x-case-declaration wire:model.live="situation.eloigne_ou_rural" :cochee="$situation['eloigne_ou_rural']"
                            libelle="J’habite en milieu rural ou loin de l’établissement" description="Milieu rural, plus de 10 km, ou filière absente de ma ville." />
                        <x-case-declaration wire:model.live="situation.demuni" :cochee="$situation['demuni']" libelle="Je suis en situation de précarité" description="Stagiaire démuni." />
                        <x-case-declaration wire:model.live="situation.orphelin_ou_divorce" :cochee="$situation['orphelin_ou_divorce']" libelle="Je suis orphelin ou mes parents sont divorcés" />
                        <x-case-declaration wire:model.live="situation.besoins_specifiques" :cochee="$situation['besoins_specifiques']"
                            libelle="J’ai des besoins spécifiques" description="Handicap ou besoin d’un hébergement adapté." />
                    </fieldset>

                    @if ($situation['besoins_specifiques'])
                        <x-zone-texte libelle="Précisions sur vos besoins" wire:model="situation.precisions_besoins" rows="2"
                            aide="Par exemple : chambre au rez-de-chaussée, salle de bain adaptée." />
                    @endif
                </x-carte.corps>
            </x-carte>
        @endif

        @if ($etape === 2)
            <x-carte>
                <x-carte.entete titre="Pièces justificatives" :description="($requises->count() - $manquantes->count()).' pièce(s) fournie(s) sur '.$requises->count().' demandée(s).'" />
                <ul>
                    @foreach ($pieces->filter(fn ($p) => $p['requise'] || $p['piece']) as $ligne)
                        @php
                            $type = $ligne['type'];
                            $piece = $ligne['piece'];
                            $verrouillee = $piece?->statut === StatutPiece::Acceptee;
                            $formats = $regles($type);
                        @endphp
                        <li class="border-b border-bord-doux px-5 py-4 last:border-0" wire:key="piece-{{ $type->id }}">
                            <div class="mb-2.5 flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="text-base font-medium">{{ $type->libelle }}</p>
                                    <p class="text-sm text-discret">{{ $type->obligatoire ? 'Demandée à tous les candidats' : 'Demandée car vous avez déclaré : '.$ligne['raison'] }}</p>
                                </div>
                                @if ($piece)<x-statut :etat="$piece->statut" />@else<x-badge ton="alerte">À fournir</x-badge>@endif
                            </div>

                            @if ($piece?->commentaire)
                                <x-alerte ton="alerte" class="mb-2.5">{{ $piece->commentaire }}</x-alerte>
                            @endif

                            @if ($piece)
                                <x-ligne-fichier :nom="$piece->nom_original" :detail="Format::taille($piece->taille).' · déposée le '.Format::dateHeure($piece->date_depot)">
                                    <x-slot:actions>
                                        <x-bouton taille="sm" variante="discret" icone="eye" wire:click="voirPiece({{ $piece->id }})">Voir</x-bouton>
                                        @if (! $verrouillee)
                                            <x-bouton taille="icone" variante="discret" aria-label="Retirer {{ $type->libelle }}" wire:click="retirerPiece({{ $piece->id }})" cible="retirerPiece({{ $piece->id }})">
                                                <x-icone nom="trash" wire:loading.remove wire:target="retirerPiece({{ $piece->id }})" />
                                            </x-bouton>
                                        @endif
                                    </x-slot:actions>
                                </x-ligne-fichier>
                            @endif

                            @if (! $verrouillee)
                                <div class="mt-2.5">
                                    <x-fichier wire:model="fichiers.{{ $type->id }}" :compact="$piece !== null" :libelle="$piece ? 'Remplacer le fichier' : 'Déposer le fichier'"
                                        :accept="'.'.implode(',.', $formats['extensions'])"
                                        :indication="mb_strtoupper(implode(', ', $formats['extensions'])).' · '.rtrim(rtrim(number_format($formats['taille_max_ko'] / 1024, 1, ',', ''), '0'), ',').' Mo maximum'" />
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-carte>
        @endif

        @if ($etape === 3)
            @php
                $declarations = collect([
                    $demande->eloigne_ou_rural ? 'Milieu rural ou éloignement' : null,
                    $demande->demuni ? 'Situation de précarité' : null,
                    $demande->orphelin_ou_divorce ? 'Orphelin ou parents divorcés' : null,
                    $demande->besoins_specifiques ? 'Besoins spécifiques' : null,
                ])->filter();
                $inscriptionSuffisante = in_array($inscription, [StatutInscription::Verifiee, StatutInscription::Soumise], true);
                $pret = $manquantes->isEmpty() && $enregistre && ($rectification || $inscriptionSuffisante);
            @endphp
            <x-carte>
                <x-carte.entete titre="Vérification avant envoi" description="Après l’envoi, votre dossier ne sera plus modifiable, sauf si le gestionnaire vous demande une rectification." />
                <x-carte.corps class="space-y-5">
                    <x-definitions :colonnes="1">
                        <x-definition terme="Internat demandé">{{ $demande->campagne->internat->nom }} — campagne {{ $demande->campagne->annee }}</x-definition>
                        <x-definition terme="Adresse">@if ($enregistre){{ $demande->adresse_complete }}@else<span class="text-danger">À renseigner à l’étape « Ma situation »</span>@endif</x-definition>
                        <x-definition terme="Déclarations">{{ $declarations->implode(' · ') ?: 'Aucune' }}</x-definition>
                        <x-definition terme="Pièces fournies">{{ $requises->count() - $manquantes->count() }} sur {{ $requises->count() }}</x-definition>
                    </x-definitions>

                    @if ($manquantes->isNotEmpty())
                        <x-alerte ton="alerte" titre="Pièces manquantes ou à remplacer">{{ $manquantes->map(fn ($p) => $p['type']->libelle)->implode(', ') }}.</x-alerte>
                    @endif
                    @if (! $rectification && ! $inscriptionSuffisante)
                        <x-alerte ton="alerte" titre="Inscription en formation à soumettre">
                            Le dépôt sera possible dès que votre inscription aura été soumise à la vérification.
                        </x-alerte>
                    @endif
                    @if ($pret)
                        <x-alerte ton="succes" titre="Votre dossier est prêt">À l’envoi, vous recevrez un accusé de réception et un numéro de dossier.</x-alerte>
                    @endif
                </x-carte.corps>
            </x-carte>
        @endif

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
            <x-bouton :disabled="$etape === 0" wire:click="allerA({{ $etape - 1 }})" icone="arrow-left">Précédent</x-bouton>
            <div class="flex flex-wrap gap-2">
                @if ($etape === 1)
                    <x-bouton wire:click="enregistrer(false)" cible="enregistrer(false)">Enregistrer le brouillon</x-bouton>
                    <x-bouton variante="primaire" wire:click="enregistrer(true)" cible="enregistrer(true)" icone-fin="arrow-right">Enregistrer et continuer</x-bouton>
                @elseif ($etape < 3)
                    <x-bouton variante="primaire" wire:click="allerA({{ $etape + 1 }})" icone-fin="arrow-right">Continuer</x-bouton>
                @else
                    <x-bouton variante="primaire" icone="send" :disabled="! $pret" cible="soumettre"
                        x-on:click="$confirmer({ titre: {{ Illuminate\Support\Js::from($rectification ? 'Soumettre de nouveau votre dossier ?' : 'Déposer votre dossier ?') }}, texte: 'Votre dossier sera transmis au gestionnaire de l’internat pour instruction. Vous ne pourrez plus le modifier.', libelle: 'Déposer' }).then(() => $wire.soumettre())">
                        {{ $rectification ? 'Soumettre de nouveau' : 'Déposer mon dossier' }}
                    </x-bouton>
                @endif
            </div>
        </div>

        @if ($dialogue === 'piece' && $pieceAffichee)
            <x-apercu :chemin="route('stagiaire.dossier.piece', $pieceAffichee)" :titre="$pieceAffichee->typePiece->libelle" :nom="$pieceAffichee->nom_original" />
        @endif
    @else
        {{-- -------------------------------------------------------- Suivi du dossier --}}
        @php
            $statut = $demande->statut;
        @endphp
        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="space-y-6">
                @switch($statut)
                    @case(StatutDemande::Recu)
                        <x-alerte ton="info" titre="Dossier reçu, en cours d’instruction">Le gestionnaire de l’internat contrôle vos pièces. Vous serez notifié de sa décision.</x-alerte>
                        @break
                    @case(StatutDemande::Complet)
                        <x-alerte ton="info" titre="Dossier complet">Votre dossier participe à la sélection. Les résultats seront publiés après la délibération.</x-alerte>
                        @break
                    @case(StatutDemande::Rejete)
                        <x-alerte ton="danger" titre="Dossier rejeté">{{ $demande->motif_rejet }}</x-alerte>
                        @break
                    @case(StatutDemande::Admis)
                        <x-carte class="border-succes-bord!">
                            <x-carte.corps class="space-y-3">
                                <div class="flex items-start gap-3">
                                    <x-icone nom="circle-check" class="mt-0.5 size-5 text-succes" />
                                    <div>
                                        <h2 class="text-md font-semibold">Vous êtes admis à l’internat</h2>
                                        <p class="mt-0.5 text-base text-encre-2">
                                            Confirmez votre place pour la conserver.
                                        </p>
                                    </div>
                                </div>
                                <p class="text-sm text-discret">Pensez à déposer votre dossier papier à l’internat.</p>
                                <div class="flex flex-wrap gap-2 pt-1">
                                    <x-bouton variante="primaire" cible="confirmer"
                                        x-on:click="$confirmer({ titre: 'Confirmer votre place ?', texte: 'Vous acceptez la place qui vous est attribuée à l’internat et vous vous engagez à déposer votre dossier papier.', libelle: 'Je confirme' }).then(() => $wire.confirmer())">
                                        Confirmer ma place
                                    </x-bouton>
                                    <x-bouton variante="danger-discret" wire:click="ouvrirDialogue('desistement')">Renoncer à ma place</x-bouton>
                                </div>
                            </x-carte.corps>
                        </x-carte>
                        @break
                    @case(StatutDemande::Confirme)
                        <x-alerte ton="succes" titre="Place confirmée">
                            Confirmée le {{ Format::dateHeure($demande->date_confirmation) }}.
                            {{ $demande->dossier_physique_depose ? 'Votre dossier papier a bien été reçu.' : 'Pensez à déposer votre dossier papier à l’internat.' }}
                            <x-slot:action><x-bouton taille="sm" :href="url('/stagiaire/paiements')">Mes paiements</x-bouton></x-slot:action>
                        </x-alerte>
                        @break
                    @case(StatutDemande::EnAttente)
                        <x-alerte ton="alerte" titre="Vous êtes en liste d’attente">
                            Si une place se libère, le chef de pôle peut vous appeler : vous serez notifié et disposerez alors de 48 heures pour confirmer.
                        </x-alerte>
                        @break
                    @case(StatutDemande::Refuse)
                        <x-alerte ton="danger" titre="Demande non retenue">Votre demande de place n’a pas été retenue. Vous pouvez déposer un recours depuis « Réclamations ».</x-alerte>
                        @break
                    @case(StatutDemande::DelaiDepasse)
                        <x-alerte ton="neutre" titre="Délai de confirmation dépassé">Vous n’avez pas confirmé votre place dans le délai : elle a été remise à disposition.</x-alerte>
                        @break
                    @case(StatutDemande::Desiste)
                        <x-alerte ton="neutre" titre="Vous avez renoncé à votre place" />
                        @break
                    @case(StatutDemande::SejourTermine)
                        <x-alerte ton="neutre" titre="Séjour terminé" />
                        @break
                @endswitch

                <x-carte>
                    <x-carte.entete titre="Mon dossier">
                        <x-slot:actions>
                            <x-bouton taille="sm" icone="download" :href="route('stagiaire.dossier.accuse')" :navigation="false">Accusé de réception</x-bouton>
                        </x-slot:actions>
                    </x-carte.entete>
                    <x-carte.corps>
                        <x-definitions>
                            <x-definition terme="Numéro de dossier"><span class="chiffres font-medium">{{ $demande->numero_dossier }}</span></x-definition>
                            <x-definition terme="Déposé le">{{ Format::dateHeure($demande->date_soumission) }}</x-definition>
                            <x-definition terme="Internat">{{ $demande->campagne->internat->nom }}</x-definition>
                            <x-definition terme="Campagne">{{ $demande->campagne->annee }}</x-definition>
                            <x-definition terme="Adresse">{{ $demande->adresse_complete }}</x-definition>
                            <x-definition terme="Inscription en formation"><x-statut :etat="$inscription" /></x-definition>
                        </x-definitions>
                    </x-carte.corps>
                </x-carte>

                <x-carte>
                    <x-carte.entete titre="Pièces déposées" />
                    <ul>
                        @foreach ($pieces->filter(fn ($p) => $p['piece']) as $ligne)
                            <li class="flex flex-wrap items-center justify-between gap-2 border-b border-bord-doux px-5 py-2.5 last:border-0">
                                <span class="text-base">{{ $ligne['type']->libelle }}</span>
                                <x-statut :etat="$ligne['piece']->statut" />
                            </li>
                        @endforeach
                    </ul>
                </x-carte>

                @if (in_array($statut, [StatutDemande::Confirme, StatutDemande::EnAttente], true))
                    <div class="flex justify-end">
                        <x-bouton variante="danger-discret" wire:click="ouvrirDialogue('desistement')">
                            {{ $statut === StatutDemande::EnAttente ? 'Me retirer de la liste d’attente' : 'Renoncer à ma place' }}
                        </x-bouton>
                    </div>
                @endif
            </div>

            <x-carte>
                <x-carte.entete titre="Suivi" description="Étapes franchies par votre dossier." />
                <ol class="px-5 py-4">
                    @foreach ($historique as $ligne)
                        <li class="relative flex gap-3 pb-4 last:pb-0">
                            @if (! $loop->last)<span aria-hidden="true" class="absolute top-3 left-[5px] h-full w-px bg-bord"></span>@endif
                            <span aria-hidden="true" @class(['relative mt-1.5 size-[11px] shrink-0 rounded-full border-2', 'border-marine bg-marine' => $loop->last, 'border-marine-200 bg-surface' => ! $loop->last])></span>
                            <div class="min-w-0">
                                <p @class(['text-base', 'font-medium' => $loop->last])>{{ StatutDemande::from($ligne->nouveau_statut)->libelle() }}</p>
                                <p class="text-sm text-discret">{{ Format::dateHeure($ligne->date_heure) }}</p>
                                {{-- Le motif n'est montré au stagiaire que pour les décisions qui le concernent directement. --}}
                                @if ($ligne->motif && in_array($ligne->nouveau_statut, ['retour_rectification', 'rejete'], true))
                                    <p class="mt-0.5 text-sm text-encre-2">{{ $ligne->motif }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-carte>
        </div>

        @if ($dialogue === 'desistement')
            <x-dialogue titre="Renoncer à votre place" description="Cette décision est définitive : la place sera proposée à un autre candidat.">
                <div class="space-y-4">
                    <x-liste libelle="Raison" wire:model="raison" :options="RaisonDesistement::options()" requis />
                    <x-zone-texte libelle="Explication" wire:model="explication" rows="4" requis autofocus
                        aide="Dix caractères au moins. Votre explication aide l’établissement à comprendre les abandons." />
                </div>
                <x-slot:pied>
                    <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                    <x-bouton variante="danger" wire:click="desister" cible="desister">Confirmer mon désistement</x-bouton>
                </x-slot:pied>
            </x-dialogue>
        @endif
    @endif
</div>
