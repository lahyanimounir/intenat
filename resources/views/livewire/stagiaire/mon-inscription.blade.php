@php
    use App\Enums\AnneeFormation;
    use App\Enums\Genre;
    use App\Enums\NiveauFormation;
    use App\Enums\StatutInscription;
    use App\Enums\StatutPiece;
    use App\Enums\StatutVerificationInscription;
    use App\Support\Affichage\Format;

    $verrouillee = ! $modifiable;
@endphp
<div class="mx-auto max-w-4xl">
    <x-page.entete titre="Mon inscription"
        description="Déposez toute votre candidature — identité, formation, situation et pièces justificatives — puis soumettez-la au gestionnaire de votre internat. L’hébergement s’ouvre une fois votre candidature validée.">
        <x-slot:marque><x-statut :etat="$statut" /></x-slot:marque>
    </x-page.entete>

    {{-- État de la candidature, toujours visible --}}
    @switch($statut)
        @case(StatutInscription::Verifiee)
            <x-alerte ton="succes" titre="Candidature validée" class="mb-5">
                Votre candidature a été validée le {{ Format::date($stagiaire->inscription_verifiee_le) }}. Vous avez maintenant accès à votre espace.
                <x-slot:action><x-bouton taille="sm" :href="route('stagiaire.accueil')" icone-fin="arrow-right">Mon espace</x-bouton></x-slot:action>
            </x-alerte>
            @break
        @case(StatutInscription::Soumise)
            <x-alerte ton="info" titre="En attente de validation" class="mb-5">
                Votre candidature a été transmise au gestionnaire de votre internat le {{ Format::date($enAttente?->soumise_le) }}. Vous serez prévenu de sa décision.
            </x-alerte>
            @break
        @case(StatutInscription::ACorriger)
            <x-alerte ton="alerte" titre="Une correction est demandée" class="mb-5">
                {{ $historique->firstWhere('statut', StatutVerificationInscription::ACorriger)?->motif }}
                <br>Corrigez votre candidature, puis soumettez-la de nouveau.
            </x-alerte>
            @break
        @case(StatutInscription::Rejetee)
            <x-alerte ton="danger" titre="Candidature rejetée" class="mb-5">
                {{ $historique->firstWhere('statut', StatutVerificationInscription::Rejetee)?->motif }}
                <br>Si vous pensez qu’il s’agit d’une erreur, corrigez votre candidature, joignez les justificatifs utiles et soumettez de nouveau.
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
                            Votre candidature est {{ $statut === StatutInscription::Verifiee ? 'validée' : 'en cours de traitement' }} : l’identité déclarée n’est plus modifiable.
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
                            aide="Numéro, rue, quartier, commune. Elle sera reprise dans votre dossier." />
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
                        <p class="mt-0.5 text-sm text-discret">
                            Choisissez votre établissement en descendant la hiérarchie — région, province, complexe, établissement —
                            puis sélectionnez une campagne ouverte. Le reste du formulaire se débloque ensuite.
                        </p>
                    </div>

                    @if ($verrouillee)
                        <x-alerte ton="neutre">
                            Votre candidature est {{ $statut === StatutInscription::Verifiee ? 'validée' : 'en cours de traitement' }} : la formation déclarée n’est plus modifiable.
                        </x-alerte>
                    @endif

                    <fieldset class="grid gap-4 sm:grid-cols-2" @disabled($verrouillee)>
                        {{-- Descente hiérarchique obligatoire : Région → Province → Complexe → Établissement --}}
                        <x-liste libelle="Région" wire:model.live="regionId" :options="$regions" vide="Choisir une région…" requis />
                        <x-liste libelle="Province" wire:model.live="provinceId" :options="$provinces" vide="Choisir une province…" requis
                            :disabled="$regionId === ''" wire:key="provinces-{{ $regionId }}"
                            :aide="$regionId === '' ? 'Choisissez d’abord une région.' : null" />
                        <x-liste libelle="Complexe / CMC" wire:model.live="complexeId" :options="$complexes" vide="Choisir un complexe…" requis
                            :disabled="$provinceId === ''" wire:key="complexes-{{ $provinceId }}"
                            :aide="$provinceId === '' ? 'Choisissez d’abord une province.' : null" />
                        <x-liste libelle="Établissement (EFP)" wire:model.live="formation.etablissement_id" :options="$etablissements" vide="Choisir un établissement…" requis
                            :disabled="$complexeId === ''" wire:key="etablissements-{{ $complexeId }}"
                            :aide="$complexeId === '' ? 'Choisissez d’abord un complexe.' : null" />

                        {{-- Verrou de campagne : aucune inscription sans campagne ouverte sélectionnée --}}
                        @if ($campagneRequise)
                            @if ($campagnes->isEmpty())
                                <div class="sm:col-span-2">
                                    <x-alerte ton="alerte" titre="Aucune campagne ouverte">
                                        Aucune campagne n’est actuellement ouverte pour l’internat
                                        {{ $stagiaire->genre?->libelle() ? mb_strtolower($stagiaire->genre->libelle()) : '' }} de cet établissement.
                                        Vous ne pouvez pas poursuivre votre inscription tant qu’une campagne n’est pas ouverte.
                                    </x-alerte>
                                </div>
                            @else
                                <div class="sm:col-span-2">
                                    <x-liste libelle="Campagne ouverte" wire:model.live="campagneId" :options="$campagnes" vide="Sélectionner la campagne…" requis
                                        wire:key="campagnes-{{ $formation['etablissement_id'] }}"
                                        aide="Sélectionnez la campagne à laquelle vous vous inscrivez pour débloquer la suite du formulaire." />
                                </div>
                            @endif
                        @endif
                    </fieldset>

                    {{-- La déclaration de formation reste masquée tant qu'une campagne ouverte n'est pas retenue. --}}
                    @if ($campagneId !== '' && $campagnes->isNotEmpty())
                        <fieldset class="grid gap-4 sm:grid-cols-2" @disabled($verrouillee)>
                            <x-champ libelle="Code EFP" pour="code-efp" aide="Renseigné d’après l’établissement choisi.">
                                <input id="code-efp" type="text" readonly value="{{ $efpChoisi?->code_efp }}" class="champ h-9 bg-neutre-fond text-encre-2" />
                            </x-champ>
                            <x-liste libelle="Année scolaire" wire:model="formation.annee_scolaire" :options="$anneesScolaires" requis />

                            <x-saisie libelle="Filière" wire:model="formation.nom_filiere" requis class="sm:col-span-2"
                                aide="Intitulé exact de votre filière. Cette information est déclarée : elle sera contrôlée lors de la vérification." />
                            <x-liste libelle="Niveau de formation" wire:model="formation.niveau_formation" :options="NiveauFormation::options()" vide="Choisir…" requis />
                            <x-liste libelle="Année de formation" wire:model="formation.annee_formation" :options="AnneeFormation::options()" vide="Choisir…" requis />
                        </fieldset>
                    @endif
                </x-carte.corps>
                <footer class="flex justify-between gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton wire:click="allerA(0)" icone="arrow-left">Identité</x-bouton>
                    @if ($verrouillee)
                        <x-bouton variante="primaire" wire:click="allerA(2)" icone-fin="arrow-right">Situation</x-bouton>
                    @else
                        <x-bouton type="submit" variante="primaire" cible="enregistrerFormation" icone-fin="arrow-right"
                            :disabled="$campagneId === '' || $campagnes->isEmpty()">Enregistrer et continuer</x-bouton>
                    @endif
                </footer>
            </form>
        @endif

        {{-- ----------------------------------------------------------- Situation --}}
        @if ($etape === 'situation')
            <form wire:submit="enregistrerSituation" novalidate>
                <x-carte.corps class="space-y-5">
                    <div>
                        <h2 class="text-md font-semibold text-encre">Ma situation</h2>
                        <p class="mt-0.5 text-sm text-discret">
                            Ces déclarations servent au calcul de votre score. Chacune doit être appuyée par une pièce justificative, demandée à l’étape suivante.
                        </p>
                    </div>

                    @if ($verrouillee)
                        <x-alerte ton="neutre">Votre candidature est en cours de traitement : votre situation n’est plus modifiable.</x-alerte>
                    @endif

                    <fieldset class="space-y-5" @disabled($verrouillee)>
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
                    </fieldset>
                </x-carte.corps>
                <footer class="flex justify-between gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton wire:click="allerA(1)" icone="arrow-left">Formation</x-bouton>
                    @if ($verrouillee)
                        <x-bouton variante="primaire" wire:click="allerA(3)" icone-fin="arrow-right">Pièces</x-bouton>
                    @else
                        <x-bouton type="submit" variante="primaire" cible="enregistrerSituation" icone-fin="arrow-right">Enregistrer et continuer</x-bouton>
                    @endif
                </footer>
            </form>
        @endif

        {{-- ------------------------------------------------------------- Pièces --}}
        @if ($etape === 'pieces')
            <x-carte.corps class="space-y-5">
                <div>
                    <h2 class="text-md font-semibold text-encre">Pièces justificatives</h2>
                    <p class="mt-0.5 text-sm text-discret">
                        Déposez les justificatifs des critères que vous avez déclarés. Les pièces obligatoires sont marquées : sans elles, la candidature ne peut pas être soumise.
                        {{ $requises->count() ? ($requises->count() - $manquantes->count()).' pièce(s) fournie(s) sur '.$requises->count().' demandée(s).' : '' }}
                    </p>
                </div>

                @if ($demande === null)
                    <x-alerte ton="alerte">Enregistrez d’abord votre situation pour ouvrir votre dossier.</x-alerte>
                @else
                    <ul class="-mx-5 border-y border-bord-doux">
                        @foreach ($pieces->filter(fn ($p) => $p['requise'] || $p['piece']) as $ligne)
                            @php
                                $type = $ligne['type'];
                                $piece = $ligne['piece'];
                                $pieceVerrouillee = $verrouillee || $piece?->statut === StatutPiece::Acceptee;
                                $formats = $reglesPiece($type);
                            @endphp
                            <li class="border-b border-bord-doux px-5 py-4 last:border-0" wire:key="piece-{{ $type->id }}">
                                <div class="mb-2.5 flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p class="text-base font-medium">{{ $type->libelle }} @if ($ligne['requise'])<span aria-hidden="true" class="text-danger">*</span>@endif</p>
                                        <p class="text-sm text-discret">{{ $type->obligatoire ? 'Demandée à tous les candidats' : 'Demandée car vous avez déclaré : '.$ligne['raison'] }}</p>
                                    </div>
                                    @if ($piece)<x-statut :etat="$piece->statut" />@elseif ($ligne['requise'])<x-badge ton="alerte">À fournir</x-badge>@endif
                                </div>

                                @if ($piece?->commentaire)
                                    <x-alerte ton="alerte" class="mb-2.5">{{ $piece->commentaire }}</x-alerte>
                                @endif

                                @if ($piece)
                                    <x-ligne-fichier :nom="$piece->nom_original" :detail="Format::taille($piece->taille).' · déposée le '.Format::dateHeure($piece->date_depot)">
                                        <x-slot:actions>
                                            <x-bouton taille="sm" variante="discret" icone="eye" wire:click="voirPiece({{ $piece->id }})">Voir</x-bouton>
                                            @if (! $pieceVerrouillee)
                                                <x-bouton taille="icone" variante="discret" aria-label="Retirer {{ $type->libelle }}" wire:click="retirerPiece({{ $piece->id }})" cible="retirerPiece({{ $piece->id }})">
                                                    <x-icone nom="trash" wire:loading.remove wire:target="retirerPiece({{ $piece->id }})" />
                                                </x-bouton>
                                            @endif
                                        </x-slot:actions>
                                    </x-ligne-fichier>
                                @endif

                                @if (! $pieceVerrouillee)
                                    <div class="mt-2.5">
                                        <x-fichier wire:model="fichiers.{{ $type->id }}" :compact="$piece !== null" :libelle="$piece ? 'Remplacer le fichier' : 'Déposer le fichier'"
                                            :accept="'.'.implode(',.', $formats['extensions'])"
                                            :indication="mb_strtoupper(implode(', ', $formats['extensions'])).' · '.rtrim(rtrim(number_format($formats['taille_max_ko'] / 1024, 1, ',', ''), '0'), ',').' Mo maximum'" />
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-carte.corps>
            <footer class="flex justify-between gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                <x-bouton wire:click="allerA(2)" icone="arrow-left">Situation</x-bouton>
                <x-bouton variante="primaire" wire:click="allerA(4)" icone-fin="arrow-right">Vérification</x-bouton>
            </footer>
        @endif

        {{-- -------------------------------------------------------- Vérification --}}
        @if ($etape === 'verification')
            <x-carte.corps class="space-y-6">
                <div>
                    <h2 class="text-md font-semibold text-encre">Vérification de ma candidature</h2>
                    <p class="mt-0.5 text-sm text-discret">
                        Voici ce que vous déclarez. Le gestionnaire de votre internat le comparera à vos pièces et aux registres de scolarité.
                    </p>
                </div>

                <x-definitions :colonnes="3">
                    <x-definition terme="CEF"><span class="chiffres">{{ $stagiaire->cef }}</span></x-definition>
                    <x-definition terme="Nom et prénom">{{ $stagiaire->nomComplet() }}</x-definition>
                    <x-definition terme="CNI">{{ $stagiaire->cni ?: '—' }}</x-definition>
                    <x-definition terme="Établissement">{{ $stagiaire->etablissement?->nom_efp }}</x-definition>
                    <x-definition terme="Code EFP">{{ $stagiaire->etablissement?->code_efp }}</x-definition>
                    <x-definition terme="Année scolaire">{{ $stagiaire->annee_scolaire }}</x-definition>
                    <x-definition terme="Filière">{{ $stagiaire->nom_filiere }}</x-definition>
                    <x-definition terme="Niveau">{{ $stagiaire->niveau_formation?->libelle() }}</x-definition>
                    <x-definition terme="Année de formation">{{ $stagiaire->annee_formation?->libelle() }}</x-definition>
                    @if ($demande)
                        <x-definition terme="Internat visé">{{ $demande->campagne->internat->nom }}</x-definition>
                        <x-definition terme="Pièces fournies">{{ $requises->count() - $manquantes->count() }} sur {{ $requises->count() }}</x-definition>
                    @endif
                </x-definitions>

                @if ($manquantes->isNotEmpty())
                    <x-alerte ton="alerte" titre="Pièces obligatoires manquantes">
                        {{ $manquantes->map(fn ($p) => $p['type']->libelle)->implode(', ') }}.
                        <x-slot:action><x-bouton taille="sm" wire:click="allerA(3)">Compléter les pièces</x-bouton></x-slot:action>
                    </x-alerte>
                @endif

                @if ($statut->soumettable())
                    <form wire:submit="soumettre" novalidate class="space-y-4 rounded-md border border-bord bg-surface-2 px-4 py-4">
                        <div>
                            <p class="text-base font-medium text-encre">Justificatif de scolarité @if ($justificatifObligatoire)<span aria-hidden="true" class="text-danger">*</span>@endif</p>
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
                            <x-bouton wire:click="allerA(3)" icone="arrow-left">Pièces</x-bouton>
                            <x-bouton type="submit" variante="primaire" cible="soumettre,justificatif" icone="send" :disabled="$manquantes->isNotEmpty()">
                                {{ $statut === StatutInscription::Declaree ? 'Soumettre ma candidature' : 'Soumettre de nouveau' }}
                            </x-bouton>
                        </div>
                    </form>
                @elseif ($statut === StatutInscription::Soumise)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-bord bg-surface-2 px-4 py-3.5">
                        <div class="min-w-0">
                            <p class="text-base font-medium text-encre">Candidature transmise le {{ Format::dateHeure($enAttente?->soumise_le) }}</p>
                            <p class="mt-0.5 text-sm text-discret">
                                @if ($demande?->numero_dossier)
                                    Dossier n° <span class="chiffres">{{ $demande->numero_dossier }}</span>.
                                @endif
                                Le gestionnaire de votre internat contrôle vos pièces et vous notifiera sa décision.
                            </p>
                        </div>
                        <x-bouton variante="danger-discret" taille="sm" icone="undo-2"
                            x-on:click="$confirmer({ titre: 'Retirer ma candidature ?', texte: 'Vous pourrez corriger votre déclaration puis la soumettre de nouveau. Tant qu’elle n’est pas soumise, votre candidature ne peut pas être validée.', libelle: 'Retirer', destructif: true }).then(() => $wire.retirer())">
                            Retirer ma candidature
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

    @if ($dialogue === 'piece' && $pieceAffichee)
        <x-apercu :chemin="route('stagiaire.inscription.piece', $pieceAffichee)" :titre="$pieceAffichee->typePiece->libelle" :nom="$pieceAffichee->nom_original" />
    @endif
</div>
