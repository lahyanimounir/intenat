<div>
    <x-bouton variante="lien" taille="sm" class="mb-4" icone="arrow-left" :href="route('connexion', ['espace' => 'stagiaire'])">Retour à la connexion</x-bouton>

    <h1 class="text-xl font-semibold text-encre">Créer mon compte INTERNAT</h1>
    <p class="mt-1 mb-5 text-base text-discret">Compte réservé aux stagiaires. Le personnel reçoit ses accès de l’administrateur.</p>

    <x-etapes :etapes="['Compte', 'Identité', 'Formation', 'Vérification', 'Hébergement']" :courante="0" />

    <x-alerte ton="info" class="mt-5" titre="Comment se déroule l’inscription">
        Vous créez votre compte, puis vous renseignez votre identité et votre formation. Votre établissement vérifie ensuite votre inscription en
        formation, sur justificatif : c’est cette vérification — et non la création du compte — qui vous permet de participer à la sélection.
    </x-alerte>

    <form wire:submit="creer" novalidate class="mt-6 space-y-4">
        <x-saisie libelle="Adresse e-mail" type="email" wire:model="email" autocomplete="username" inputmode="email" requis
            :aide="$adresseAVerifier ? 'Un lien de confirmation vous sera envoyé à cette adresse.' : 'Elle servira à vous connecter et à recevoir les notifications.'" />

        <x-nouveau-mot-de-passe libelle="Mot de passe INTERNAT" />

        <x-bouton type="submit" variante="primaire" class="w-full sm:w-auto" cible="creer" icone-fin="arrow-right">Créer mon compte et continuer</x-bouton>
    </form>
</div>
