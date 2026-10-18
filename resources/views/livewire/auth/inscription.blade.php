@php
    use App\Enums\AnneeFormation;
    use App\Enums\Genre;
    use App\Enums\NiveauFormation;
@endphp
<div>
    <x-bouton variante="lien" taille="sm" class="mb-4" icone="arrow-left" :href="route('connexion')">Retour à la connexion</x-bouton>

    <h1 class="text-xl font-semibold text-encre">Inscription à l’internat</h1>
    <p class="mt-1 mb-5 text-base text-discret">
        Renseignez toute votre candidature. Votre compte n’est créé qu’au moment où vous la soumettez — aucune donnée n’est enregistrée avant.
    </p>

    <x-etapes :etapes="$etapes" :courante="$etapeCourante" action="allerA" />

    <x-carte class="mt-6">
        {{-- ------------------------------------------------------------- Compte --}}
        @if ($etapeNom === 'compte')
            <form wire:submit="continuerCompte" novalidate>
                <x-carte.corps class="space-y-5">
                    <div>
                        <h2 class="text-md font-semibold text-encre">Mon compte</h2>
                        <p class="mt-0.5 text-sm text-discret">Compte réservé aux stagiaires. Il vous servira à suivre votre candidature.</p>
                    </div>
                    <x-saisie libelle="Adresse e-mail" type="email" wire:model="compte.email" autocomplete="username" inputmode="email" requis
                        aide="Elle servira à vous connecter et à recevoir les notifications. Aucune confirmation ne vous sera demandée." />
                    <x-nouveau-mot-de-passe :modele="'compte.mot_de_passe'" libelle="Mot de passe INTERNAT" />
                </x-carte.corps>
                <footer class="flex justify-end gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton type="submit" variante="primaire" cible="continuerCompte" icone-fin="arrow-right">Continuer</x-bouton>
                </footer>
            </form>
        @endif

        {{-- ----------------------------------------------------------- Identité --}}
        @if ($etapeNom === 'identite')
            <form wire:submit="continuerIdentite" novalidate>
                <x-carte.corps class="space-y-5">
                    <div>
                        <h2 class="text-md font-semibold text-encre">Mon identité</h2>
                        <p class="mt-0.5 text-sm text-discret">Saisissez ces informations telles qu’elles figurent sur vos documents d’inscription.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-saisie libelle="CEF" wire:model="identite.cef" inputmode="numeric" maxlength="13" autocomplete="off" requis aide="Code d’enregistrement de la formation : 13 chiffres." />
                        <x-saisie libelle="CNI" wire:model="identite.cni" maxlength="10" autocomplete="off" aide="Carte nationale d’identité, si vous en possédez une." />
                        <x-saisie libelle="Nom" wire:model="identite.nom" autocomplete="family-name" requis />
                        <x-saisie libelle="Prénom" wire:model="identite.prenom" autocomplete="given-name" requis />
                        <x-liste libelle="Genre" wire:model="identite.genre" :options="Genre::options()" vide="Choisir…" requis
                            aide="Il détermine l’internat (filles ou garçons) auquel vous pouvez postuler." />
                        <x-saisie libelle="Téléphone" type="tel" wire:model="identite.tel" autocomplete="tel" inputmode="tel" requis aide="Exemple : 0612345678." />
                        <x-saisie libelle="Ville" wire:model="identite.ville" autocomplete="address-level2" requis />
                        <x-saisie libelle="Adresse complète" wire:model="identite.adresse" autocomplete="street-address" requis class="sm:col-span-2" />
                    </div>
                </x-carte.corps>
                <footer class="flex justify-between gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton wire:click="allerA(0)" icone="arrow-left">Compte</x-bouton>
                    <x-bouton type="submit" variante="primaire" cible="continuerIdentite" icone-fin="arrow-right">Continuer</x-bouton>
                </footer>
            </form>
        @endif

        {{-- ---------------------------------------------------------- Formation --}}
        @if ($etapeNom === 'formation')
            <form wire:submit="continuerFormation" novalidate>
                <x-carte.corps class="space-y-5">
                    <div>
                        <h2 class="text-md font-semibold text-encre">Ma formation</h2>
                        <p class="mt-0.5 text-sm text-discret">Choisissez votre établissement en descendant la hiérarchie, puis une campagne ouverte.</p>
                    </div>
                    <fieldset class="grid gap-4 sm:grid-cols-2">
                        <x-liste libelle="Région" wire:model.live="regionId" :options="$regions" vide="Choisir une région…" requis />
                        <x-liste libelle="Province" wire:model.live="provinceId" :options="$provinces" vide="Choisir une province…" requis
                            :disabled="$regionId === ''" wire:key="provinces-{{ $regionId }}" :aide="$regionId === '' ? 'Choisissez d’abord une région.' : null" />
                        <x-liste libelle="Complexe / CMC" wire:model.live="complexeId" :options="$complexes" vide="Choisir un complexe…" requis
                            :disabled="$provinceId === ''" wire:key="complexes-{{ $provinceId }}" :aide="$provinceId === '' ? 'Choisissez d’abord une province.' : null" />
                        <x-liste libelle="Établissement (EFP)" wire:model.live="formation.etablissement_id" :options="$etablissements" vide="Choisir un établissement…" requis
                            :disabled="$complexeId === ''" wire:key="etablissements-{{ $complexeId }}" :aide="$complexeId === '' ? 'Choisissez d’abord un complexe.' : null" />

                        @if ($campagneRequise)
                            @if ($campagnes->isEmpty())
                                <div class="sm:col-span-2">
                                    <x-alerte ton="alerte" titre="Aucune campagne ouverte">
                                        Aucune campagne n’est actuellement ouverte pour l’internat {{ $identite['genre'] === Genre::Fille->value ? 'filles' : ($identite['genre'] === Genre::Garcon->value ? 'garçons' : '') }} de cet établissement.
                                    </x-alerte>
                                </div>
                            @else
                                <div class="sm:col-span-2">
                                    <x-liste libelle="Campagne ouverte" wire:model.live="campagneId" :options="$campagnes" vide="Sélectionner la campagne…" requis
                                        wire:key="campagnes-{{ $formation['etablissement_id'] }}" aide="Sélectionnez la campagne à laquelle vous vous inscrivez." />
                                </div>
                            @endif
                        @endif
                    </fieldset>

                    @if ($campagneId !== '' && $campagnes->isNotEmpty())
                        <fieldset class="grid gap-4 sm:grid-cols-2">
                            <x-champ libelle="Code EFP" pour="code-efp" aide="Renseigné d’après l’établissement choisi.">
                                <input id="code-efp" type="text" readonly value="{{ $efpChoisi?->code_efp }}" class="champ h-9 bg-neutre-fond text-encre-2" />
                            </x-champ>
                            <x-liste libelle="Année scolaire" wire:model="formation.annee_scolaire" :options="$anneesScolaires" requis />
                            <x-saisie libelle="Filière" wire:model="formation.nom_filiere" requis class="sm:col-span-2" aide="Intitulé exact de votre filière." />
                            <x-liste libelle="Niveau de formation" wire:model="formation.niveau_formation" :options="NiveauFormation::options()" vide="Choisir…" requis />
                            <x-liste libelle="Année de formation" wire:model="formation.annee_formation" :options="AnneeFormation::options()" vide="Choisir…" requis />
                        </fieldset>
                    @endif
                </x-carte.corps>
                <footer class="flex justify-between gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton wire:click="allerA(1)" icone="arrow-left">Identité</x-bouton>
                    <x-bouton type="submit" variante="primaire" cible="continuerFormation" icone-fin="arrow-right" :disabled="$campagneId === '' || $campagnes->isEmpty()">Continuer</x-bouton>
                </footer>
            </form>
        @endif

        {{-- ---------------------------------------------------------- Situation --}}
        @if ($etapeNom === 'situation')
            <form wire:submit="continuerSituation" novalidate>
                <x-carte.corps class="space-y-5">
                    <div>
                        <h2 class="text-md font-semibold text-encre">Ma situation</h2>
                        <p class="mt-0.5 text-sm text-discret">Ces déclarations servent au calcul de votre score. Chacune doit être appuyée par une pièce, demandée à l’étape suivante.</p>
                    </div>
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
                        <x-zone-texte libelle="Précisions sur vos besoins" wire:model="situation.precisions_besoins" rows="2" aide="Par exemple : chambre au rez-de-chaussée, salle de bain adaptée." />
                    @endif
                </x-carte.corps>
                <footer class="flex justify-between gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton wire:click="allerA(2)" icone="arrow-left">Formation</x-bouton>
                    <x-bouton type="submit" variante="primaire" cible="continuerSituation" icone-fin="arrow-right">Continuer</x-bouton>
                </footer>
            </form>
        @endif

        {{-- ------------------------------------------------------------- Pièces --}}
        @if ($etapeNom === 'pieces')
            <x-carte.corps class="space-y-5">
                <div>
                    <h2 class="text-md font-semibold text-encre">Pièces justificatives</h2>
                    <p class="mt-0.5 text-sm text-discret">
                        Déposez les justificatifs des critères déclarés. Les pièces obligatoires sont marquées : sans elles, la candidature ne peut pas être soumise.
                    </p>
                </div>

                @error('pieces')<x-alerte ton="alerte">{{ $message }}</x-alerte>@enderror

                <ul class="-mx-5 border-y border-bord-doux">
                    @foreach ($pieces->filter(fn ($p) => $p['requise']) as $ligne)
                        @php $type = $ligne['type']; $formats = $reglesPiece($type); $depose = isset($fichiers[(string) $type->id]); @endphp
                        <li class="border-b border-bord-doux px-5 py-4 last:border-0" wire:key="piece-{{ $type->id }}">
                            <div class="mb-2.5 flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="text-base font-medium">{{ $type->libelle }} <span aria-hidden="true" class="text-danger">*</span></p>
                                    <p class="text-sm text-discret">{{ $type->obligatoire ? 'Demandée à tous les candidats' : 'Demandée car vous avez déclaré : '.$ligne['raison'] }}</p>
                                </div>
                                @if ($depose)<x-badge ton="succes">Fournie</x-badge>@else<x-badge ton="alerte">À fournir</x-badge>@endif
                            </div>
                            <x-fichier wire:model="fichiers.{{ $type->id }}" :fichier="$fichiers[(string) $type->id] ?? null" :compact="$depose"
                                :libelle="$depose ? 'Remplacer le fichier' : 'Déposer le fichier'"
                                :accept="'.'.implode(',.', $formats['extensions'])"
                                :indication="mb_strtoupper(implode(', ', $formats['extensions'])).' · '.rtrim(rtrim(number_format($formats['taille_max_ko'] / 1024, 1, ',', ''), '0'), ',').' Mo maximum'" />
                            @error('fichiers.'.$type->id)<p class="mt-1.5 text-sm text-danger">{{ $message }}</p>@enderror
                        </li>
                    @endforeach
                </ul>
            </x-carte.corps>
            <footer class="flex justify-between gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                <x-bouton wire:click="allerA(3)" icone="arrow-left">Situation</x-bouton>
                <x-bouton wire:click="continuerPieces" variante="primaire" cible="continuerPieces" icone-fin="arrow-right">Continuer</x-bouton>
            </footer>
        @endif

        {{-- -------------------------------------------------------- Vérification --}}
        @if ($etapeNom === 'verification')
            <form wire:submit="soumettre" novalidate>
                <x-carte.corps class="space-y-5">
                    <div>
                        <h2 class="text-md font-semibold text-encre">Vérification et envoi</h2>
                        <p class="mt-0.5 text-sm text-discret">Vérifiez votre candidature, joignez votre justificatif de scolarité, puis soumettez. Votre compte sera alors créé.</p>
                    </div>

                    <x-definitions :colonnes="3">
                        <x-definition terme="E-mail">{{ $compte['email'] }}</x-definition>
                        <x-definition terme="Nom et prénom">{{ trim($identite['prenom'].' '.$identite['nom']) }}</x-definition>
                        <x-definition terme="CEF"><span class="chiffres">{{ $identite['cef'] }}</span></x-definition>
                        <x-definition terme="Établissement">{{ $efpChoisi?->nom_efp }}</x-definition>
                        <x-definition terme="Filière">{{ $formation['nom_filiere'] }}</x-definition>
                        <x-definition terme="Pièces fournies">{{ $requises->count() - $manquantes->count() }} sur {{ $requises->count() }}</x-definition>
                    </x-definitions>

                    @if ($manquantes->isNotEmpty())
                        <x-alerte ton="alerte" titre="Pièces obligatoires manquantes">
                            {{ $manquantes->implode(', ') }}.
                            <x-slot:action><x-bouton taille="sm" wire:click="allerA(4)">Compléter les pièces</x-bouton></x-slot:action>
                        </x-alerte>
                    @endif

                    @error('soumission')<x-alerte ton="danger">{{ $message }}</x-alerte>@enderror

                    <div class="rounded-md border border-bord bg-surface-2 px-4 py-4 space-y-3">
                        <div>
                            <p class="text-base font-medium text-encre">Justificatif de scolarité @if ($justificatifObligatoire)<span aria-hidden="true" class="text-danger">*</span>@endif</p>
                            <p class="mt-0.5 text-sm text-discret">Attestation d’inscription ou reçu des frais de scolarité, à votre nom. PDF, JPG ou PNG, {{ round(config('internat.documents.taille_max_ko') / 1024) }} Mo au plus.</p>
                        </div>
                        <x-fichier wire:model="justificatif" :fichier="$justificatif" accept=".pdf,.jpg,.jpeg,.png" />
                    </div>
                </x-carte.corps>
                <footer class="flex justify-between gap-2 border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton wire:click="allerA(4)" icone="arrow-left">Pièces</x-bouton>
                    <x-bouton type="submit" variante="primaire" cible="soumettre,justificatif" icone="send" :disabled="$manquantes->isNotEmpty()">Soumettre ma candidature</x-bouton>
                </footer>
            </form>
        @endif
    </x-carte>
</div>
