{{-- Accueil de l'espace stagiaires et candidats : se connecter, créer son compte, récupérer son mot de passe. --}}
<div>
    <h1 class="text-xl font-semibold text-encre">Espace stagiaires</h1>
    <p class="mt-1 mb-6 text-base text-discret">Demandez une place à l’internat et suivez votre séjour.</p>

    @if (session('succes'))
        <x-alerte ton="succes" class="mb-5">{{ session('succes') }}</x-alerte>
    @endif

    @if (config('internat.mode_demo'))
        <x-alerte ton="alerte" class="mb-5" titre="Environnement de démonstration">
            Données synthétiques. Le mot de passe des comptes de démonstration est défini par <code>INTERNAT_DEMO_MOT_DE_PASSE</code> dans le fichier <code>.env</code> du serveur.
        </x-alerte>
    @endif

    <section aria-labelledby="titre-connexion" class="rounded-md border border-bord bg-surface px-4 py-4">
        <h2 id="titre-connexion" class="mb-4 text-base font-semibold text-encre">Se connecter</h2>
        <form wire:submit="connecter" novalidate class="space-y-4">
            @if ($refus)
                <x-alerte ton="danger">{{ $refus }}</x-alerte>
            @endif

            <x-saisie libelle="Adresse e-mail" type="email" wire:model="email" autocomplete="username" inputmode="email" requis />
            <x-mot-de-passe libelle="Mot de passe INTERNAT" wire:model="mot_de_passe" autocomplete="current-password" requis />

            <x-bouton type="submit" variante="primaire" class="w-full" cible="connecter">Se connecter</x-bouton>

            <p class="text-center text-sm">
                <a href="{{ route('mot-de-passe.oubli') }}" wire:navigate class="rounded-sm text-bleu underline-offset-4 hover:underline">Mot de passe oublié ?</a>
            </p>
        </form>
    </section>

    <section aria-labelledby="titre-inscription" class="mt-4 rounded-md border border-bord bg-surface px-4 py-3.5">
        <h2 id="titre-inscription" class="text-base font-medium text-encre">Nouveau candidat ?</h2>
        <p class="mt-0.5 text-sm text-discret">
            Renseignez en six étapes votre identité, votre formation, votre situation et vos pièces. Votre compte est créé
            lorsque vous soumettez votre candidature.
        </p>
        <x-bouton :href="route('inscription')" class="mt-3" taille="sm" icone="user-plus">Créer mon compte</x-bouton>
    </section>
</div>
