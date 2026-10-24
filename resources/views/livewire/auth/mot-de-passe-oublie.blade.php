<div>
    <x-bouton variante="lien" taille="sm" class="mb-4" icone="arrow-left" :href="route($espace === 'personnel' ? 'backend.connexion' : 'connexion')">Retour à la connexion</x-bouton>

    @if ($envoyeA)
        <section>
            <span class="flex size-11 items-center justify-center rounded-full bg-info-fond text-info">
                <x-icone nom="mail-check" class="size-6" trait="1.75" />
            </span>
            <h1 class="mt-4 text-xl font-semibold text-encre">Consultez votre messagerie</h1>
            <p role="status" class="mt-2 text-base text-encre-2">
                Si un compte de l’espace {{ $espace }} correspond à <strong class="font-medium break-all text-encre">{{ $envoyeA }}</strong>, un lien de
                réinitialisation vient de lui être envoyé. Il est valable 30 minutes et ne sert qu’une fois.
            </p>
            <p class="mt-3 text-sm text-discret">
                Rien reçu ? Vérifiez l’adresse et le dossier des courriers indésirables.
                {{ $espace === 'stagiaire' ? 'Si vous n’avez pas encore de compte, créez-le.' : 'Sinon, adressez-vous à l’administrateur.' }}
            </p>
            @if ($espace === 'stagiaire')
                <x-bouton :href="route('inscription')" class="mt-5">Créer mon compte</x-bouton>
            @endif
        </section>
    @else
        <h1 class="text-xl font-semibold text-encre">Mot de passe oublié</h1>
        <p class="mt-1 mb-6 text-base text-discret">
            Espace {{ $espace }}. Indiquez l’adresse e-mail de votre compte Maisons des Stagiaires - OFPPT : vous y recevrez un lien pour choisir un nouveau mot de passe.
        </p>
        <form wire:submit="envoyer" novalidate class="space-y-4">
            <x-saisie libelle="Adresse e-mail" type="email" wire:model="email" autocomplete="username" inputmode="email" requis />
            <x-bouton type="submit" variante="primaire" class="w-full" cible="envoyer">Recevoir le lien</x-bouton>
        </form>
    @endif
</div>
