<div>
    <h1 class="text-xl font-semibold text-encre">Connexion</h1>
    <p class="mt-1 mb-6 text-base text-discret">Choisissez votre espace pour vous identifier.</p>

    @if (session('succes'))
        <x-alerte ton="succes" class="mb-5">{{ session('succes') }}</x-alerte>
    @endif

    @if (config('internat.mode_demo'))
        <x-alerte ton="alerte" class="mb-5" titre="Environnement de démonstration">
            Données synthétiques. Le mot de passe des comptes de démonstration est défini par <code>INTERNAT_DEMO_MOT_DE_PASSE</code> dans le fichier <code>.env</code> du serveur.
        </x-alerte>
    @endif

    <x-onglets libelle="Espace de connexion">
        <x-onglet :actif="$espace === 'personnel'" wire:click="choisir('personnel')">Personnel</x-onglet>
        <x-onglet :actif="$espace === 'stagiaire'" wire:click="choisir('stagiaire')">Stagiaire</x-onglet>
    </x-onglets>

    <div class="pt-5" wire:key="espace-{{ $espace }}">
        <form wire:submit="connecter" novalidate class="space-y-4">
            @if ($refus)
                <x-alerte ton="danger">{{ $refus }}</x-alerte>
            @endif

            <x-saisie libelle="Adresse e-mail" type="email" wire:model="email" autocomplete="username" inputmode="email" requis />
            <x-mot-de-passe :libelle="$espace === 'stagiaire' ? 'Mot de passe INTERNAT' : 'Mot de passe'" wire:model="mot_de_passe" autocomplete="current-password" requis />

            <x-bouton type="submit" variante="primaire" class="w-full" cible="connecter">Se connecter</x-bouton>

            <p class="text-center text-sm">
                <a href="{{ route('mot-de-passe.oubli', ['espace' => $espace]) }}" wire:navigate class="rounded-sm text-bleu underline-offset-4 hover:underline">Mot de passe oublié ?</a>
            </p>
        </form>

        @if ($espace === 'stagiaire')
            <div class="mt-6 rounded-md border border-bord bg-surface px-4 py-3.5">
                <p class="text-base font-medium text-encre">Première visite ?</p>
                <p class="mt-0.5 text-sm text-discret">
                    Créez votre compte INTERNAT, renseignez votre identité et votre formation, puis faites vérifier votre inscription par votre établissement.
                </p>
                <x-bouton :href="route('inscription')" class="mt-3" taille="sm">Créer mon compte</x-bouton>
            </div>
        @elseif ($demonstration->isNotEmpty())
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
</div>
