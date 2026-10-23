<div>
    <h1 class="text-xl font-semibold text-encre">Choisissez votre mot de passe</h1>
    <p class="mt-1 mb-6 text-base text-discret">
        Bonjour {{ $compte->prenom }}. Votre mot de passe a été réinitialisé par un responsable : celui que vous avez utilisé est provisoire.
        Remplacez-le maintenant par un mot de passe personnel, que vous seul connaîtrez.
    </p>

    <form wire:submit="enregistrer" novalidate class="space-y-4">
        <x-nouveau-mot-de-passe libelle="Nouveau mot de passe" />
        <x-bouton type="submit" variante="primaire" class="w-full" cible="enregistrer">Enregistrer mon mot de passe</x-bouton>
    </form>

    <form method="POST" action="{{ route('deconnexion') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-sm font-medium text-bleu underline-offset-4 hover:underline">Me déconnecter</button>
    </form>
</div>
