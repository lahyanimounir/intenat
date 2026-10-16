@php
    use App\Enums\AnneeFormation;
    use App\Enums\Genre;
    use App\Enums\NiveauFormation;
    use App\Enums\StatutInscription;
    use App\Enums\StatutVerificationInscription;
    use App\Support\Affichage\Format;

    $verrouillee = ! $modifiable;
@endphp
<div class="mx-auto max-w-4xl">
    <x-page.entete titre="Mon inscription"
        description="Renseignez votre identité et votre formation, puis faites vérifier votre inscription par votre établissement. Cette vérification est nécessaire pour participer à la sélection.">
        <x-slot:marque><x-statut :etat="$statut" /></x-slot:marque>
    </x-page.entete>

    {{-- État de la vérification, toujours visible --}}
    @switch($statut)
        @case(StatutInscription::Verifiee)
            <x-alerte ton="succes" titre="Inscription vérifiée" class="mb-5">
                Votre inscription en formation a été vérifiée le {{ Format::date($stagiaire->inscription_verifiee_le) }}. Vous pouvez déposer votre dossier d’hébergement.
                <x-slot:action><x-bouton taille="sm" :href="route('stagiaire.dossier')" icone-fin="arrow-right">Mon dossier</x-bouton></x-slot:action>
            </x-alerte>
            @break
        @case(StatutInscription::Soumise)
            <x-alerte ton="info" titre="En attente de vérification" class="mb-5">
                Votre déclaration a été transmise à votre établissement le {{ Format::date($enAttente?->soumise_le) }}. Vous serez prévenu de la décision.
                En attendant, vous pouvez préparer votre dossier d’hébergement.
            </x-alerte>
            @break
        @case(StatutInscription::ACorriger)
            <x-alerte ton="alerte" titre="Une correction est demandée" class="mb-5">
                {{ $historique->firstWhere('statut', StatutVerificationInscription::ACorriger)?->motif }}
                <br>Corrigez votre déclaration ou votre justificatif, puis soumettez de nouveau.
            </x-alerte>
            @break
        @case(StatutInscription::Rejetee)
            <x-alerte ton="danger" titre="Inscription rejetée" class="mb-5">
                {{ $historique->firstWhere('statut', StatutVerificationInscription::Rejetee)?->motif }}
                <br>Si vous pensez qu’il s’agit d’une erreur, corrigez votre déclaration, joignez un justificatif valable et soumettez de nouveau.
            </x-alerte>
            @break
    @endswitch

    <x-carte>
        <div class="border-b border-bord-doux px-5 py-4">
            <x-etapes :etapes="$etapes" :courante="$indice" action="allerA" />
        </div>

        {{-- ------------------------------------------------------------ Identité --}}
        @if ($etape === 'identite')
            <form wire:submit="enregistrerIdentite" novalidate>
                <x-carte.corps class="space-y-5">
                    <div>
                        <h2 class="text-md font-semibold text-encre">Mon identité</h2>
                        <p class="mt-0.5 text-sm text-discret">
                            Saisissez ces informations telles qu’elles figurent sur vos documents d’inscription : elles seront comparées à votre justificatif.
                        </p>
                    </div>

                    @if ($verrouillee)
                        <x-alerte ton="neutre">
                            Votre inscription est {{ $statut === StatutInscription::Verifiee ? 'vérifiée' : 'en cours de vérification' }} : l’identité déclarée n’est plus modifiable.
                            Vos coordonnées (téléphone, ville, adresse) le restent.
                        </x-alerte>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-saisie libelle="CEF" wire:model="identite.cef" inputmode="numeric" maxlength="13" autocomplete="off" requis :disabled="$verrouillee"
                            aide="Code d’enregistrement de la formation : 13 chiffres." />
                        <x-saisie libelle="CNI" wire:model="identite.cni" maxlength="10" autocomplete="off" :disabled="$verrouillee"
                            aide="Carte nationale d’identité, si vous en possédez une." />
                        <x-saisie libelle="Nom" wire:model="identite.nom" autocomplete="family-name" requis :disabled="$verrouillee" />
                        <x-saisie libelle="Prénom" wire:model="identite.prenom" autocomplete="given-name" requis :disabled="$verrouillee" />
                        <x-liste libelle="Genre" wire:model="identite.genre" :options="Genre::options()" vide="Choisir…" requis
                            :disabled="$verrouillee || $stagiaire->genreVerrouille()" aide="Il détermine l’internat (filles ou garçons) auquel vous pouvez postuler." />
                        <x-saisie libelle="Téléphone" type="tel" wire:model="identite.tel" autocomplete="tel" inputmode="tel" requis aide="Exemple : 0612345678." />
                        <x-saisie libelle="Ville" wire:model="identite.ville" autocomplete="address-level2" requis />
                        <x-saisie libelle="Adresse complète" wire:model="identite.adresse" autocomplete="street-address" requis class="sm:col-span-2"
                            aide="Numéro, rue, quartier, commune. Elle sera reprise dans votre dossier d’hébergement." />
                    </div>
                </x-carte.corps>
                <footer class="flex justify-end gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton type="submit" variante="primaire" cible="enregistrerIdentite" icone-fin="arrow-right">Enregistrer et continuer</x-bouton>
                </footer>
            </form>
        @endif

        {{-- ----------------------------------------------------------- Formation --}}
        @if ($etape === 'formation')
            <form wire:submit="enregistrerFormation" novalidate>
                <x-carte.corps class="space-y-5">
                    <div>
                        <h2 class="text-md font-semibold text-encre">Ma formation</h2>
                        <p class="mt-0.5 text-sm text-discret">L’établissement se choisit dans la liste tenue par l’administration : il ne peut pas être saisi librement.</p>
                    </div>

                    @if ($verrouillee)
                        <x-alerte ton="neutre">
                            Votre inscription est {{ $statut === StatutInscription::Verifiee ? 'vérifiée' : 'en cours de vérification' }} : la formation déclarée n’est plus modifiable.
                            @if ($statut === StatutInscription::Soumise) Retirez votre demande, à l’étape « Vérification », pour la corriger.@endif
                        </x-alerte>
                    @endif

                    <fieldset class="grid gap-4 sm:grid-cols-2" @disabled($verrouillee)>
                        <div class="space-y-2 sm:col-span-2">
                            <x-saisie libelle="Rechercher mon établissement" wire:model.live.debounce.300ms="rechercheEfp" type="search" placeholder="Nom de l’établissement ou code EFP" autocomplete="off" />
                            <x-liste libelle="Établissement (EFP)" wire:model.live="formation.etablissement_id" vide="Choisir dans la liste…" requis
                                :aide="$etablissements->count() >= 80 ? 'Liste limitée aux 80 premiers résultats : précisez la recherche.' : null">
                                @foreach ($etablissements as $etablissement)
                                    <option value="{{ $etablissement->id }}">{{ $etablissement->nom_efp }} — {{ $etablissement->code_efp }}</option>
                                @endforeach
                            </x-liste>
                        </div>

                        <x-champ libelle="Code EFP" pour="code-efp" aide="Renseigné d’après l’établissement choisi.">
                            <input id="code-efp" type="text" readonly value="{{ $efpChoisi?->code_efp }}" class="champ h-9 bg-neutre-fond text-encre-2" />
                        </x-champ>
                        <x-liste libelle="Année scolaire" wire:model="formation.annee_scolaire" :options="$anneesScolaires" requis />

                        <x-saisie libelle="Filière" wire:model="formation.nom_filiere" requis class="sm:col-span-2"
                            aide="Intitulé exact de votre filière. Cette information est déclarée : elle sera contrôlée lors de la vérification." />
                        <x-liste libelle="Niveau de formation" wire:model="formation.niveau_formation" :options="NiveauFormation::options()" vide="Choisir…" requis />
                        <x-liste libelle="Année de formation" wire:model="formation.annee_formation" :options="AnneeFormation::options()" vide="Choisir…" requis />
                    </fieldset>
                </x-carte.corps>
                <footer class="flex justify-between gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton wire:click="allerA(0)" icone="arrow-left">Identité</x-bouton>
                    @if ($verrouillee)
                        <x-bouton variante="primaire" wire:click="allerA(2)" icone-fin="arrow-right">Vérification</x-bouton>
                    @else
                        <x-bouton type="submit" variante="primaire" cible="enregistrerFormation" icone-fin="arrow-right">Enregistrer et continuer</x-bouton>
                    @endif
                </footer>
            </form>
        @endif

        {{-- -------------------------------------------------------- Vérification --}}
        @if ($etape === 'verification')
            <x-carte.corps class="space-y-6">
                <div>
                    <h2 class="text-md font-semibold text-encre">Vérification de mon inscription</h2>
                    <p class="mt-0.5 text-sm text-discret">
                        Voici ce que vous déclarez. Un agent de votre établissement le comparera à votre justificatif et aux registres de scolarité.
                    </p>
                </div>

                <x-definitions :colonnes="3">
                    <x-definition terme="CEF"><span class="chiffres">{{ $stagiaire->cef }}</span></x-definition>
                    <x-definition terme="Nom et prénom">{{ $stagiaire->nomComplet() }}</x-definition>
                    <x-definition terme="CNI">{{ $stagiaire->cni }}</x-definition>
                    <x-definition terme="Établissement">{{ $stagiaire->etablissement?->nom_efp }}</x-definition>
                    <x-definition terme="Code EFP">{{ $stagiaire->etablissement?->code_efp }}</x-definition>
                    <x-definition terme="Année scolaire">{{ $stagiaire->annee_scolaire }}</x-definition>
                    <x-definition terme="Filière">{{ $stagiaire->nom_filiere }}</x-definition>
                    <x-definition terme="Niveau">{{ $stagiaire->niveau_formation?->libelle() }}</x-definition>
                    <x-definition terme="Année de formation">{{ $stagiaire->annee_formation?->libelle() }}</x-definition>
                </x-definitions>

                @if ($statut->soumettable())
                    <form wire:submit="soumettre" novalidate class="space-y-4 rounded-md border border-bord bg-surface-2 px-4 py-4">
                        <div>
                            <p class="text-base font-medium text-encre">Justificatif d’inscription @if ($justificatifObligatoire)<span aria-hidden="true" class="text-danger">*</span>@endif</p>
                            <p class="mt-0.5 text-sm text-discret">
                                Attestation d’inscription ou reçu des frais de scolarité de l’année {{ $stagiaire->annee_scolaire }}, à votre nom.
                                PDF, JPG ou PNG, {{ round(config('internat.documents.taille_max_ko') / 1024) }} Mo au plus.
                            </p>
                        </div>

                        <x-fichier wire:model="justificatif" :fichier="$justificatif" accept=".pdf,.jpg,.jpeg,.png" />

                        <x-alerte ton="neutre">
                            Ce justificatif concerne vos <strong class="font-medium">frais de scolarité</strong>. Il est sans rapport avec le paiement de l’hébergement,
                            qui ne vous sera demandé qu’après une éventuelle admission.
                        </x-alerte>

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <x-bouton wire:click="allerA(1)" icone="arrow-left">Formation</x-bouton>
                            <x-bouton type="submit" variante="primaire" cible="soumettre,justificatif" icone="send">
                                {{ $statut === StatutInscription::Declaree ? 'Soumettre à la vérification' : 'Soumettre de nouveau' }}
                            </x-bouton>
                        </div>
                    </form>
                @elseif ($statut === StatutInscription::Soumise)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-bord bg-surface-2 px-4 py-3.5">
                        <div class="min-w-0">
                            <p class="text-base font-medium text-encre">Demande transmise le {{ Format::dateHeure($enAttente?->soumise_le) }}</p>
                            <p class="mt-0.5 text-sm text-discret">
                                @if ($enAttente?->aUnJustificatif())
                                    Justificatif : <a href="{{ route('stagiaire.inscription.justificatif', $enAttente) }}" target="_blank" rel="noopener" class="text-bleu underline-offset-4 hover:underline">{{ $enAttente->nom_original }}</a>
                                    ({{ Format::taille($enAttente->taille) }})
                                @else
                                    Aucun justificatif joint.
                                @endif
                            </p>
                        </div>
                        <x-bouton variante="danger-discret" taille="sm" icone="undo-2"
                            x-on:click="$confirmer({ titre: 'Retirer ma demande ?', texte: 'Vous pourrez corriger votre déclaration puis la soumettre de nouveau. Tant qu’elle n’est pas soumise, votre inscription ne peut pas être vérifiée.', libelle: 'Retirer la demande', destructif: true }).then(() => $wire.retirer())">
                            Retirer ma demande
                        </x-bouton>
                    </div>
                @endif

                @if ($historique->isNotEmpty())
                    <div>
                        <h3 class="mb-2 text-sm font-medium text-encre">Historique</h3>
                        <ul class="divide-y divide-bord-doux rounded-md border border-bord">
                            @foreach ($historique as $ligne)
                                <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 px-3.5 py-2.5">
                                    <div class="min-w-0">
                                        <p class="text-sm text-encre">
                                            {{ $ligne->origine->libelle() }}
                                            <span class="text-discret">· {{ Format::dateHeure($ligne->decidee_le ?? $ligne->soumise_le) }}</span>
                                        </p>
                                        @if ($ligne->motif)<p class="mt-0.5 text-sm text-discret">{{ $ligne->motif }}</p>@endif
                                    </div>
                                    <x-statut :etat="$ligne->statut" />
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-carte.corps>
        @endif
    </x-carte>
</div>
