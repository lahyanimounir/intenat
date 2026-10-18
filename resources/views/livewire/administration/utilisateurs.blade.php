@php use App\Support\Affichage\Format; @endphp
<div>
    <x-page.entete titre="Utilisateurs"
        description="Comptes du personnel. Chaque compte porte un rôle et un périmètre : il n’accède qu’aux données de l’internat, de l’établissement ou de la région dont il a la charge. Les stagiaires n’ont pas de compte ici : ils créent le leur eux-mêmes.">
        <x-slot:actions>
            <x-bouton variante="primaire" icone="plus" wire:click="creer">Nouveau compte</x-bouton>
        </x-slot:actions>
    </x-page.entete>

    <x-tableau legende="Comptes du personnel" :lignes="$comptes" :tri="$tri" :sens="$sens" vide="Aucun compte ne correspond aux filtres">
        <x-slot:barre>
            {{-- Hiérarchie administrative : un niveau ne s'ouvre qu'une fois son parent choisi. --}}
            <x-filtre libelle="Région" wire:model.live="region" :options="$filtre['regions']" tous="toutes" :actif="$region !== ''" />
            <x-filtre libelle="Province" wire:model.live="province" :options="$filtre['provinces']" tous="toutes" :actif="$province !== ''"
                :disabled="$region === ''" wire:key="filtre-province-{{ $region }}" />
            <x-filtre libelle="Complexe / CMC" wire:model.live="complexe" :options="$filtre['complexes']" :actif="$complexe !== ''"
                :disabled="$province === ''" wire:key="filtre-complexe-{{ $province }}" />
            <x-filtre libelle="Établissement" wire:model.live="etablissement" :options="$filtre['etablissements']" :actif="$etablissement !== ''"
                :disabled="$complexe === ''" wire:key="filtre-etablissement-{{ $complexe }}" />
            <x-recherche placeholder="Nom ou adresse e-mail" libelle="Rechercher un compte" />
            <x-filtre libelle="Rôle" wire:model.live="role" :options="$roles" :actif="$role !== ''" />
            <x-filtre libelle="État" wire:model.live="actif" :options="['1' => 'Actifs', '0' => 'Désactivés']" :actif="$actif !== ''" />
            @if ($filtresActifs)
                <x-bouton taille="sm" variante="discret" icone="x" wire:click="effacerFiltres">Effacer les filtres</x-bouton>
            @endif
        </x-slot:barre>

        <x-slot:entete>
            <x-tableau.colonne cle="nom">Compte</x-tableau.colonne>
            <x-tableau.colonne>Rôle</x-tableau.colonne>
            <x-tableau.colonne>Périmètre</x-tableau.colonne>
            <x-tableau.colonne cle="derniere_connexion">Dernière connexion</x-tableau.colonne>
            <x-tableau.colonne>État</x-tableau.colonne>
            <x-tableau.colonne><span class="sr-only">Actions</span></x-tableau.colonne>
        </x-slot:entete>

        @foreach ($comptes as $compte)
            @php $nomComplet = $compte->nomComplet(); @endphp
            <x-tableau.ligne wire:key="compte-{{ $compte->id }}">
                <x-tableau.cellule>
                    <p class="font-medium text-encre">{{ $nomComplet }} @if ($compte->id === $moi)<x-badge class="ml-1">Vous</x-badge>@endif</p>
                    <p class="text-xs text-discret">{{ $compte->email }}</p>
                </x-tableau.cellule>
                <x-tableau.cellule>
                    @if ($compte->roleCode())
                        <x-badge ton="marine">{{ $compte->roleCode()->libelle() }}</x-badge>
                    @else
                        <x-badge ton="danger">Sans rôle</x-badge>
                    @endif
                </x-tableau.cellule>
                <x-tableau.cellule class="text-encre-2">{{ $compte->internat?->nom ?? $compte->etablissement?->nom_efp ?? $compte->complexe?->nom ?? $compte->region?->nom ?? 'National' }}</x-tableau.cellule>
                <x-tableau.cellule class="chiffres text-encre-2">{{ Format::dateHeure($compte->derniere_connexion_le) }}</x-tableau.cellule>
                <x-tableau.cellule><x-badge :ton="$compte->actif ? 'succes' : 'neutre'">{{ $compte->actif ? 'Actif' : 'Désactivé' }}</x-badge></x-tableau.cellule>
                <x-tableau.cellule droite>
                    <div class="flex justify-end gap-1">
                        <x-bouton variante="discret" taille="icone" icone="pencil" aria-label="Modifier {{ $nomComplet }}" wire:click="modifier({{ $compte->id }})" />
                        @if ($compte->id !== $moi)
                            <x-bouton variante="discret" taille="icone" icone="power" aria-label="{{ $compte->actif ? 'Désactiver' : 'Réactiver' }} {{ $nomComplet }}"
                                x-on:click="$confirmer({
                                    titre: {{ Js::from($compte->actif ? 'Désactiver ce compte ?' : 'Réactiver ce compte ?') }},
                                    texte: {{ Js::from($compte->actif ? $nomComplet.' ne pourra plus se connecter. Son historique d’actions est conservé.' : $nomComplet.' pourra de nouveau se connecter avec son rôle et son périmètre.') }},
                                    libelle: {{ Js::from($compte->actif ? 'Désactiver' : 'Réactiver') }}, destructif: {{ $compte->actif ? 'true' : 'false' }},
                                }).then(() => $wire.basculer({{ $compte->id }}))" />
                        @endif
                    </div>
                </x-tableau.cellule>
            </x-tableau.ligne>
        @endforeach
    </x-tableau>

    @if ($dialogue === 'compte')
        <x-dialogue :titre="$ligne ? 'Modifier le compte' : 'Nouveau compte'" largeur="lg">
            <form wire:submit="enregistrer" id="form-compte" novalidate class="grid gap-4 sm:grid-cols-2">
                <x-saisie libelle="Prénom" wire:model="saisie.prenom" autocomplete="off" maxlength="100" requis autofocus />
                <x-saisie libelle="Nom" wire:model="saisie.nom" autocomplete="off" maxlength="100" requis />
                <x-saisie libelle="Adresse e-mail (identifiant)" type="email" wire:model="saisie.email" autocomplete="off" maxlength="190" requis class="sm:col-span-2" />

                <x-liste libelle="Rôle" wire:model.live="saisie.role" :options="$roles" :aide="$aide" requis class="sm:col-span-2" />

                {{-- Le périmètre demandé dépend du rôle : un seul niveau est conservé. --}}
                @if ($niveau === 'internat')
                    <x-liste libelle="Internat dont il a la charge" wire:model="saisie.internat_id" :options="$internats" vide="Choisir un internat…" requis class="sm:col-span-2" wire:key="perimetre-internat" />
                @endif
                @if (in_array($niveau, ['etablissement', 'complexe'], true))
                    <x-liste libelle="Établissement" wire:model="saisie.etablissement_id" :options="$etablissements" wire:key="perimetre-etablissement-{{ $niveau }}"
                        :vide="$niveau === 'complexe' ? 'Aucun' : 'Choisir un établissement…'" :requis="$niveau === 'etablissement'"
                        :aide="$niveau === 'complexe' ? 'À renseigner si le directeur ne couvre qu’un établissement.' : null"
                        :class="$niveau === 'etablissement' ? 'sm:col-span-2' : ''" />
                @endif
                @if ($niveau === 'complexe')
                    <x-liste libelle="Complexe / CMC" wire:model="saisie.complexe_id" :options="$complexes" vide="Aucun" aide="Prioritaire sur l’établissement s’il est renseigné." wire:key="perimetre-complexe" />
                @endif
                @if ($niveau === 'region')
                    <x-liste libelle="Région" wire:model="saisie.region_id" :options="$regions" vide="Choisir une région…" requis class="sm:col-span-2" wire:key="perimetre-region" />
                @endif

                <x-mot-de-passe :libelle="$ligne ? 'Nouveau mot de passe' : 'Mot de passe initial'" wire:model="saisie.mot_de_passe" autocomplete="new-password" :requis="! $ligne" class="sm:col-span-2"
                    :aide="$ligne ? 'Laissez vide pour le conserver.' : 'Dix caractères au moins, avec majuscule, minuscule et chiffre.'" />

                <div class="flex items-center gap-3 sm:col-span-2">
                    <x-interrupteur libelle="Compte actif" wire:model="saisie.actif" />
                    <span class="text-base" aria-hidden="true">Compte actif</span>
                </div>
            </form>
            <x-slot:pied>
                <x-bouton wire:click="fermerDialogue">Annuler</x-bouton>
                <x-bouton type="submit" form="form-compte" variante="primaire" cible="enregistrer">Enregistrer</x-bouton>
            </x-slot:pied>
        </x-dialogue>
    @endif
</div>
