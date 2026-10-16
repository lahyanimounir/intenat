@php
    use App\Enums\OrigineVerificationInscription;
    use App\Enums\StatutInscription;
    use App\Enums\StatutVerificationInscription;
    use App\Support\Affichage\Format;
    use Illuminate\Support\Js;

    $statut = $stagiaire->statut_inscription;
    $nom = $stagiaire->nomComplet();
@endphp
<div>
    <x-page.entete :titre="$nom" :fil="[['Inscriptions à vérifier', route('inscriptions')], [$nom, null]]"
        description="Comparez ce que le stagiaire déclare à son justificatif et au registre de scolarité, puis rendez votre décision.">
        <x-slot:marque><x-statut :etat="$statut" /></x-slot:marque>
    </x-page.entete>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="space-y-5">
            {{-- ------------------------------------------------- Décision à rendre --}}
            @if ($demande)
                <x-carte>
                    <x-carte.entete titre="Demande en attente" :description="'Soumise le '.Format::dateHeure($demande->soumise_le)" />
                    <x-carte.corps class="space-y-4">
                        @if ($demande->aUnJustificatif())
                            <x-ligne-fichier :nom="$demande->nom_original" :detail="Format::taille($demande->taille).' · justificatif d’inscription'">
                                <x-slot:actions>
                                    <x-bouton taille="sm" icone="eye" wire:click="voirJustificatif({{ $demande->id }})">Consulter</x-bouton>
                                </x-slot:actions>
                            </x-ligne-fichier>
                        @else
                            <x-alerte ton="alerte">Aucun justificatif n’accompagne cette demande : vérifiez l’inscription sur le registre de scolarité.</x-alerte>
                        @endif

                        <div class="flex flex-wrap items-center gap-2 border-t border-bord-doux pt-4">
                            <x-bouton variante="primaire" icone="badge-check"
                                x-on:click="$confirmer({ titre: 'Vérifier cette inscription ?', texte: {{ Js::from('Vous attestez que '.$nom.' est inscrit(e) en formation pour l’année '.$demande->annee_scolaire.', frais de scolarité acquittés. Son dossier pourra participer à la sélection.') }}, libelle: 'Inscription vérifiée' }).then(() => $wire.decider('verifiee'))">
                                Inscription vérifiée
                            </x-bouton>
                            <x-bouton icone="pencil"
                                x-on:click="$motif({ titre: 'Demander une correction', description: 'Le stagiaire verra ce message et pourra corriger sa déclaration ou son justificatif.', libelle: 'Ce qui doit être corrigé', libelleAction: 'Demander la correction' }).then((motif) => $wire.decider('a_corriger', motif))">
                                Demander une correction
                            </x-bouton>
                            <x-bouton variante="danger-discret" icone="ban"
                                x-on:click="$motif({ titre: 'Rejeter cette inscription', description: 'Le stagiaire ne pourra pas déposer de dossier tant que son inscription n’est pas vérifiée. Le motif lui est communiqué.', libelle: 'Motif du rejet', libelleAction: 'Rejeter', destructif: true }).then((motif) => $wire.decider('rejetee', motif))">
                                Rejeter
                            </x-bouton>
                        </div>
                    </x-carte.corps>
                </x-carte>
            @elseif ($reprise && $statut === StatutInscription::Verifiee)
                <x-alerte ton="alerte" titre="Inscription reprise de MyWay, jamais contrôlée par un agent">
                    Ce compte date de l’ancienne première connexion par MyWay. Confirmez son inscription après contrôle, ou remettez-la en cause.
                </x-alerte>
            @endif

            {{-- ------------------------------------------------------ Déclaration --}}
            <x-carte>
                <x-carte.entete titre="Ce que le stagiaire déclare" description="Les informations d’identité et de formation sont figées tant que la demande est en attente, et après vérification." />
                <x-carte.corps class="space-y-5">
                    <x-definitions :colonnes="3">
                        <x-definition terme="CEF"><span class="chiffres">{{ $stagiaire->cef }}</span></x-definition>
                        <x-definition terme="Nom">{{ $stagiaire->nom }}</x-definition>
                        <x-definition terme="Prénom">{{ $stagiaire->prenom }}</x-definition>
                        <x-definition terme="CNI">{{ $stagiaire->cni }}</x-definition>
                        <x-definition terme="Genre">{{ $stagiaire->genre?->libelle() }}</x-definition>
                        <x-definition terme="Année scolaire">{{ $stagiaire->annee_scolaire }}</x-definition>
                        <x-definition terme="Établissement">{{ $stagiaire->etablissement?->nom_efp }}</x-definition>
                        <x-definition terme="Code EFP">{{ $stagiaire->etablissement?->code_efp }}</x-definition>
                        <x-definition terme="Année de formation">{{ $stagiaire->annee_formation?->libelle() }}</x-definition>
                        <x-definition terme="Filière" class="sm:col-span-2">
                            {{ $stagiaire->nom_filiere }}
                            <x-badge class="ml-1.5">Déclarée</x-badge>
                        </x-definition>
                        <x-definition terme="Niveau">{{ $stagiaire->niveau_formation?->libelle() }}</x-definition>
                    </x-definitions>
                    <p class="text-sm text-discret">
                        L’établissement vient du référentiel tenu par l’administration. La filière est une saisie libre du stagiaire : contrôlez-la sur le justificatif.
                    </p>
                </x-carte.corps>
            </x-carte>

            {{-- ------------------------------------------- Décision sans demande --}}
            @if (! $demande)
                <x-carte>
                    <x-carte.entete titre="Décision de l’administration"
                        description="Sans demande en attente, toute décision exige un motif écrit : il dit sur quoi vous vous êtes fondé et reste dans l’historique." />
                    <x-carte.corps class="flex flex-wrap items-center gap-2">
                        @if ($statut !== StatutInscription::Verifiee || $reprise)
                            <x-bouton variante="primaire" icone="badge-check"
                                x-on:click="$motif({ titre: {{ Js::from($reprise ? 'Confirmer cette inscription' : 'Vérifier sans justificatif') }}, description: 'Indiquez la source de votre contrôle : registre de scolarité, liste des inscrits, pièce présentée au guichet…', libelle: 'Fondement de la vérification', libelleAction: 'Inscription vérifiée' }).then((motif) => $wire.deciderSansDemande('verifiee', motif))">
                                {{ $reprise ? 'Confirmer l’inscription' : 'Vérifier sur le registre' }}
                            </x-bouton>
                        @endif
                        @if ($statut === StatutInscription::Verifiee)
                            <x-bouton variante="danger-discret" icone="ban"
                                x-on:click="$motif({ titre: 'Remettre en cause cette inscription', description: 'Un dossier déclaré complet repassera en instruction et quittera la sélection. Impossible si un dossier est déjà délibéré.', libelle: 'Motif', libelleAction: 'Rejeter l’inscription', destructif: true }).then((motif) => $wire.deciderSansDemande('rejetee', motif))">
                                Remettre en cause
                            </x-bouton>
                        @elseif ($statut === StatutInscription::Declaree)
                            <x-bouton icone="pencil"
                                x-on:click="$motif({ titre: 'Demander une correction', description: 'Le stagiaire verra ce message.', libelle: 'Ce qui doit être corrigé', libelleAction: 'Demander la correction' }).then((motif) => $wire.deciderSansDemande('a_corriger', motif))">
                                Demander une correction
                            </x-bouton>
                        @endif
                    </x-carte.corps>
                </x-carte>
            @endif

            {{-- ------------------------------------------------------- Historique --}}
            <x-carte>
                <x-carte.entete titre="Historique des vérifications" />
                @if ($historique->isEmpty())
                    <x-vide titre="Aucune vérification" class="py-8!" />
                @else
                    <ul class="divide-y divide-bord-doux">
                        @foreach ($historique as $ligne)
                            <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1.5 px-5 py-3" wire:key="historique-{{ $ligne->id }}">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-encre">
                                        <span class="font-medium">{{ $ligne->origine->libelle() }}</span>
                                        <span class="text-discret">· {{ Format::dateHeure($ligne->decidee_le ?? $ligne->soumise_le) }}</span>
                                    </p>
                                    <p class="mt-0.5 text-sm text-discret">
                                        @if ($ligne->agent)
                                            Décision de {{ $ligne->agent->nomComplet() }}
                                        @elseif ($ligne->origine === OrigineVerificationInscription::Reprise)
                                            Aucun agent : reprise automatique
                                        @elseif ($ligne->statut === StatutVerificationInscription::Soumise)
                                            En attente d’un agent
                                        @else
                                            Retirée par le stagiaire
                                        @endif
                                        · {{ $ligne->etablissement?->nom_efp ?? '—' }} · {{ $ligne->nom_filiere ?? '—' }} · {{ $ligne->annee_scolaire ?? '—' }}
                                    </p>
                                    @if ($ligne->motif)<p class="mt-1 text-sm text-encre-2">{{ $ligne->motif }}</p>@endif
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    @if ($ligne->aUnJustificatif())
                                        <x-bouton variante="discret" taille="sm" icone="paperclip" wire:click="voirJustificatif({{ $ligne->id }})">Justificatif</x-bouton>
                                    @endif
                                    <x-statut :etat="$ligne->statut" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-carte>
        </div>

        {{-- ------------------------------------------------------- Colonne latérale --}}
        <aside class="space-y-5">
            <x-carte>
                <x-carte.entete titre="Compte" />
                <x-carte.corps>
                    <x-definitions :colonnes="1">
                        <x-definition terme="Adresse e-mail"><span class="break-all">{{ $stagiaire->email }}</span></x-definition>
                        <x-definition terme="Adresse confirmée"><x-oui-non :valeur="$stagiaire->adresseVerifiee()" oui="Confirmée" non="Non confirmée" /></x-definition>
                        <x-definition terme="Téléphone"><span class="chiffres">{{ $stagiaire->tel }}</span></x-definition>
                        <x-definition terme="Ville">{{ $stagiaire->ville }}</x-definition>
                        <x-definition terme="Compte créé le">{{ Format::date($stagiaire->inscrit_le) }}</x-definition>
                        <x-definition terme="État du compte"><x-statut :etat="$stagiaire->etat_compte" /></x-definition>
                    </x-definitions>
                </x-carte.corps>
            </x-carte>

            <x-carte>
                <x-carte.entete titre="Hébergement" />
                <x-carte.corps class="space-y-3">
                    <x-definitions :colonnes="1">
                        <x-definition terme="Internat concerné">{{ $internat?->nom ?? 'Aucun internat pour ce genre dans l’établissement' }}</x-definition>
                    </x-definitions>
                    @forelse ($dossiers as $dossier)
                        <div class="flex items-center justify-between gap-3 rounded-md border border-bord px-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-encre">{{ $dossier->numero_dossier ?? 'Dossier en brouillon' }}</p>
                                <p class="text-xs text-discret">Campagne {{ $dossier->campagne?->annee }}</p>
                            </div>
                            <x-statut :etat="$dossier->statut" />
                        </div>
                    @empty
                        <p class="text-sm text-discret">Aucun dossier d’hébergement.</p>
                    @endforelse
                </x-carte.corps>
            </x-carte>
        </aside>
    </div>

    @if ($dialogue === 'justificatif' && $justificatif)
        <x-apercu :chemin="route('inscriptions.justificatif', $justificatif)" titre="Justificatif d’inscription" :nom="$justificatif->nom_original" />
    @endif
</div>
