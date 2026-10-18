@php
    use App\Enums\EtatCompte;
    use App\Enums\StatutInscription;
    use App\Support\Affichage\Format;

    $tonsEtat = ['actif' => 'succes', 'suspendu' => 'danger', 'en_attente' => 'neutre'];
    $tonsBlocage = ['informations_incompletes' => 'alerte', 'efp_inconnu' => 'alerte', 'candidature_ambigue' => 'info', 'conflit_identite' => 'danger'];
@endphp
<div>
    <x-page.entete titre="Comptes stagiaires"
        description="Chaque stagiaire crée lui-même son compte : personne d’autre ne choisit ni ne voit son mot de passe. Un compte actif ne prouve pas l’inscription en formation, qui est vérifiée à part par les agents habilités." />

    @if ($archives > 0)
        <x-onglets libelle="Sections">
            <x-onglet :actif="$onglet === 'comptes'" wire:click="voir('comptes')">Comptes</x-onglet>
            <x-onglet :actif="$onglet === 'archive'" wire:click="voir('archive')" :compteur="$archives">Archive MyWay</x-onglet>
        </x-onglets>
    @endif

    <div @class(['pt-5' => $archives > 0]) wire:key="onglet-{{ $onglet }}">
        @if ($onglet === 'comptes')
            <x-tableau legende="Comptes stagiaires" :lignes="$comptes" :tri="$tri" :sens="$sens"
                :vide="$filtresActifs ? 'Aucun compte ne correspond aux filtres' : 'Aucun compte stagiaire pour l’instant'">
                <x-slot:barre>
                    <x-filtres-hierarchiques :filtre="$filtre" :region="$region" :province="$province" :complexe="$complexe" :etablissement="$etablissement" />
                    <x-recherche placeholder="Nom, CEF ou adresse e-mail" libelle="Rechercher un stagiaire" />
                    <x-filtre libelle="État" wire:model.live="etat" :options="EtatCompte::options()" :actif="$etat !== ''" />
                    <x-filtre libelle="Inscription" wire:model.live="inscription" :options="StatutInscription::options()" tous="toutes" :actif="$inscription !== ''" />
                    @if ($filtresActifs)
                        <x-bouton taille="sm" variante="discret" icone="x" wire:click="effacerFiltres">Effacer les filtres</x-bouton>
                    @endif
                </x-slot:barre>

                <x-slot:entete>
                    <x-tableau.colonne cle="nom">Stagiaire</x-tableau.colonne>
                    <x-tableau.colonne cle="cef">CEF et établissement</x-tableau.colonne>
                    <x-tableau.colonne>État du compte</x-tableau.colonne>
                    <x-tableau.colonne>Inscription en formation</x-tableau.colonne>
                    <x-tableau.colonne cle="cree_le">Créé le</x-tableau.colonne>
                    <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
                </x-slot:entete>

                @foreach ($comptes as $compte)
                    @php
                        $nomComplet = trim($compte->prenom.' '.$compte->nom) ?: 'Identité non déclarée';
                        $description = $nomComplet.($compte->cef ? ' — CEF '.$compte->cef : '');
                    @endphp
                    <x-tableau.ligne wire:key="stagiaire-{{ $compte->id }}">
                        <x-tableau.cellule>
                            <div class="min-w-0">
                                <p @class(['truncate font-medium', 'text-encre' => $compte->nom, 'text-discret' => ! $compte->nom])>{{ $nomComplet }}</p>
                                <p class="truncate text-sm text-discret">{{ $compte->email }}</p>
                            </div>
                        </x-tableau.cellule>
                        <x-tableau.cellule>
                            <p class="chiffres">{{ $compte->cef ?? '—' }}</p>
                            <p class="text-sm text-discret" @if ($compte->etablissement) title="{{ $compte->etablissement->nom_efp }}" @endif>{{ $compte->etablissement?->code_efp ?? 'Établissement non déclaré' }}</p>
                        </x-tableau.cellule>
                        <x-tableau.cellule>
                            <x-statut :etat="$compte->etat_compte" :ton="$tonsEtat[$compte->etat_compte->value] ?? 'neutre'" />
                            @if ($compte->motif_suspension)<p class="mt-0.5 max-w-56 truncate text-sm text-discret" title="{{ $compte->motif_suspension }}">{{ $compte->motif_suspension }}</p>@endif
                        </x-tableau.cellule>
                        <x-tableau.cellule><x-statut :etat="$compte->statut_inscription" /></x-tableau.cellule>
                        <x-tableau.cellule class="whitespace-nowrap">
                            <p class="chiffres">{{ Format::date($compte->inscrit_le ?? $compte->compte_active_le) }}</p>
                            <p class="chiffres text-sm text-discret">{{ $compte->derniere_connexion_le ? 'Connexion le '.Format::date($compte->derniere_connexion_le) : 'Jamais connecté' }}</p>
                        </x-tableau.cellule>
                        <x-tableau.cellule droite>
                            <div class="flex justify-end gap-1.5">
                                @if ($compte->etat_compte === EtatCompte::Actif)
                                    <x-bouton taille="sm" variante="discret"
                                        x-on:click="$confirmer({ titre: 'Envoyer un lien de réinitialisation ?', texte: {{ Js::from('Un lien à usage unique, valable 30 minutes, sera envoyé à '.$compte->email.'. Vous ne choisissez ni ne voyez le mot de passe.') }}, libelle: 'Envoyer' }).then(() => $wire.envoyerLien({{ $compte->id }}))">
                                        Envoyer un lien
                                    </x-bouton>
                                    <x-bouton taille="sm" variante="danger-discret"
                                        x-on:click="$motif({ titre: 'Suspendre ce compte ?', description: {{ Js::from($description) }}, texte: 'Le stagiaire ne pourra plus se connecter et sa session en cours sera fermée. Son dossier, son hébergement et son historique sont conservés.', libelle: 'Motif de la suspension', libelleAction: 'Suspendre', destructif: true }).then((motif) => $wire.suspendre({{ $compte->id }}, motif))">
                                        Suspendre
                                    </x-bouton>
                                @elseif ($compte->etat_compte === EtatCompte::Suspendu)
                                    <x-bouton taille="sm"
                                        x-on:click="$confirmer({ titre: 'Rétablir ce compte ?', texte: {{ Js::from($nomComplet.' pourra de nouveau se connecter avec son mot de passe INTERNAT.') }}, libelle: 'Rétablir' }).then(() => $wire.retablir({{ $compte->id }}))">
                                        Rétablir
                                    </x-bouton>
                                @endif
                            </div>
                        </x-tableau.cellule>
                    </x-tableau.ligne>
                @endforeach
            </x-tableau>
        @else
            <x-alerte ton="neutre" class="mb-4" titre="Archive en lecture seule">
                Ces premières connexions par MyWay n’avaient pas abouti : aucun compte n’avait été créé et aucun profil n’est conservé. L’intégration MyWay est
                abandonnée ; ces lignes restent consultables comme trace, sans action possible.
            </x-alerte>

            <x-tableau legende="Vérifications MyWay non abouties (archive)" :lignes="$verifications" vide="Aucune vérification archivée">
                <x-slot:entete>
                    <x-tableau.colonne>Adresse vérifiée</x-tableau.colonne>
                    <x-tableau.colonne>Ce qui avait bloqué</x-tableau.colonne>
                    <x-tableau.colonne>Source</x-tableau.colonne>
                    <x-tableau.colonne>Dernière tentative</x-tableau.colonne>
                    <x-tableau.colonne>Examen</x-tableau.colonne>
                </x-slot:entete>
                @foreach ($verifications as $verification)
                    <x-tableau.ligne wire:key="verification-{{ $verification->id }}">
                        <x-tableau.cellule>
                            <div class="min-w-0">
                                <p class="truncate font-medium text-encre">{{ $verification->email ?? '—' }}</p>
                                <p class="chiffres text-sm text-discret">{{ $verification->cef ? 'CEF '.$verification->cef : 'CEF non communiqué' }}</p>
                            </div>
                        </x-tableau.cellule>
                        <x-tableau.cellule>
                            <div class="max-w-80">
                                <x-statut :etat="$verification->resultat" :ton="$tonsBlocage[$verification->resultat->value] ?? 'neutre'" />
                                @if ($verification->nom_efp)<p class="mt-0.5 text-sm text-encre-2">« {{ $verification->nom_efp }} »</p>@endif
                                @if ($verification->champs_manquants)<p class="mt-0.5 text-sm text-encre-2">Manquait : {{ implode(', ', $verification->champs_manquants) }}</p>@endif
                            </div>
                        </x-tableau.cellule>
                        <x-tableau.cellule>
                            @if ($verification->simulee)<x-badge ton="alerte">Simulée</x-badge>@else<code class="text-xs">{{ $verification->fournisseur }}</code>@endif
                        </x-tableau.cellule>
                        <x-tableau.cellule class="chiffres whitespace-nowrap">{{ Format::dateHeure($verification->updated_at) }}</x-tableau.cellule>
                        <x-tableau.cellule>
                            @if ($verification->examinee_le)
                                <div class="max-w-72">
                                    <p class="text-sm text-encre">{{ $verification->note_examen }}</p>
                                    <p class="text-sm text-discret">{{ $verification->examinateur?->nomComplet() }} · {{ Format::date($verification->examinee_le) }}</p>
                                </div>
                            @else
                                <span class="text-sm text-discret">Non examinée</span>
                            @endif
                        </x-tableau.cellule>
                    </x-tableau.ligne>
                @endforeach
            </x-tableau>
        @endif
    </div>
</div>
