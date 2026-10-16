@php use App\Support\Affichage\Format; @endphp
<div class="mx-auto max-w-4xl">
    <x-page.entete titre="Mon profil" description="Votre compte INTERNAT. L’identité et la formation se déclarent dans « Mon inscription »." />

    <div class="space-y-6">
        <x-carte>
            <x-carte.entete titre="Mes informations">
                <x-slot:actions>
                    <x-bouton taille="sm" icone="pencil" :href="route('stagiaire.inscription', ['etape' => 'identite'])">Modifier mes coordonnées</x-bouton>
                </x-slot:actions>
            </x-carte.entete>
            <x-carte.corps>
                <x-definitions :colonnes="3">
                    <x-definition terme="Nom et prénom">{{ $stagiaire->nomComplet() }}</x-definition>
                    <x-definition terme="CEF"><span class="chiffres">{{ $stagiaire->cef }}</span></x-definition>
                    <x-definition terme="CNI">{{ $stagiaire->cni }}</x-definition>
                    <x-definition terme="Genre">{{ $stagiaire->genre?->libelle() }}</x-definition>
                    <x-definition terme="Téléphone"><span class="chiffres">{{ $stagiaire->tel }}</span></x-definition>
                    <x-definition terme="Ville">{{ $stagiaire->ville }}</x-definition>
                    <x-definition terme="Adresse" class="sm:col-span-2 lg:col-span-3">{{ $stagiaire->adresse }}</x-definition>
                    <x-definition terme="Établissement">{{ $stagiaire->etablissement?->nom_efp }}</x-definition>
                    <x-definition terme="Filière">{{ $stagiaire->nom_filiere }}</x-definition>
                    <x-definition terme="Niveau et année">{{ $stagiaire->niveau_formation?->libelle() }} · {{ $stagiaire->annee_formation?->libelle() }}</x-definition>
                    <x-definition terme="Année scolaire">{{ $stagiaire->annee_scolaire }}</x-definition>
                    <x-definition terme="Inscription en formation"><x-statut :etat="$stagiaire->statut_inscription" /></x-definition>
                </x-definitions>
            </x-carte.corps>
        </x-carte>

        <x-carte>
            <x-carte.entete titre="Mon compte" />
            <x-carte.corps>
                <x-definitions :colonnes="3">
                    <x-definition terme="Adresse e-mail"><span class="break-all">{{ $stagiaire->email }}</span></x-definition>
                    <x-definition terme="Adresse confirmée"><x-oui-non :valeur="$stagiaire->adresseVerifiee()" oui="Confirmée" non="Non confirmée" /></x-definition>
                    <x-definition terme="Compte créé le">{{ Format::date($stagiaire->inscrit_le) }}</x-definition>
                    <x-definition terme="Dernière connexion">{{ Format::dateHeure($stagiaire->derniere_connexion_le) }}</x-definition>
                    <x-definition terme="Mot de passe modifié le">{{ Format::date($stagiaire->mot_de_passe_modifie_le) }}</x-definition>
                </x-definitions>
            </x-carte.corps>
        </x-carte>

        <x-carte>
            <x-carte.entete titre="Changer mon mot de passe" />
            <form wire:submit="changerMotDePasse" novalidate>
                <x-carte.corps class="max-w-md space-y-4">
                    <x-mot-de-passe libelle="Mot de passe actuel" wire:model="actuel" autocomplete="current-password" requis />
                    <x-nouveau-mot-de-passe libelle="Nouveau mot de passe" />
                </x-carte.corps>
                <footer class="flex justify-end border-t border-bord-doux bg-surface-2 px-5 py-3">
                    <x-bouton type="submit" variante="primaire" cible="changerMotDePasse">Enregistrer</x-bouton>
                </footer>
            </form>
        </x-carte>
    </div>
</div>
