{{-- Entrée de l'espace personnel : aucun lien vers l'espace stagiaire. --}}
<div>
    <h1 class="text-xl font-semibold text-encre">Espace personnel</h1>
    <p class="mt-1 mb-6 text-base text-discret">Réservé aux comptes du personnel de l’OFPPT. Identifiez-vous avec l’adresse de votre compte.</p>

    @if (session('succes'))
        <x-alerte ton="succes" class="mb-5">{{ session('succes') }}</x-alerte>
    @endif

    @if (config('internat.mode_demo'))
        <x-alerte ton="alerte" class="mb-5" titre="Environnement de démonstration">
            Données synthétiques. Le mot de passe des comptes de démonstration est défini par <code>INTERNAT_DEMO_MOT_DE_PASSE</code> dans le fichier <code>.env</code> du serveur.
        </x-alerte>
    @endif

    <form wire:submit="connecter" novalidate class="space-y-4">
        @if ($refus)
            <x-alerte ton="danger">{{ $refus }}</x-alerte>
        @endif

        <x-saisie libelle="Adresse e-mail" type="email" wire:model="email" autocomplete="username" inputmode="email" requis />
        <x-mot-de-passe libelle="Mot de passe" wire:model="mot_de_passe" autocomplete="current-password" requis />

        <x-bouton type="submit" variante="primaire" class="w-full" cible="connecter">Se connecter</x-bouton>

        <p class="text-center text-sm">
            <a href="{{ route('backend.mot-de-passe.oubli') }}" wire:navigate class="rounded-sm text-bleu underline-offset-4 hover:underline">Mot de passe oublié ?</a>
        </p>
    </form>

    @if ($demonstration->isNotEmpty())
        <details class="mt-5 rounded-md border border-bord bg-surface text-sm">
            <summary class="px-3.5 py-2.5 font-medium text-encre-2 select-none">Comptes du personnel (démonstration)</summary>
            <ul class="border-t border-bord-doux px-3.5 py-2">
                @foreach ($demonstration as $compte)
                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 py-1">
                        <span class="text-discret">{{ $compte['role'] }}</span>
                        <code class="text-xs text-encre">{{ $compte['email'] }}</code>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
