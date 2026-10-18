{{-- Le jeton est lu une seule fois dans le fragment de l'adresse, puis retiré de la barre d'adresse et de l'historique : il ne vit ensuite qu'en mémoire. --}}
@php
    $connexion = route($espace === 'personnel' ? 'backend.connexion' : 'connexion');
    $oubli = route($espace === 'personnel' ? 'backend.mot-de-passe.oubli' : 'mot-de-passe.oubli');
    $autrePage = route($espace === 'personnel' ? 'mot-de-passe.reinitialisation' : 'backend.mot-de-passe.reinitialisation');
@endphp
<div x-data="{
        jeton: '',
        init() {
            const fragment = new URLSearchParams(window.location.hash.replace(/^#/, ''))
            // Lien envoyé avant la séparation des espaces : il désigne l'espace dans le fragment.
            const ancien = fragment.get('espace')
            if (ancien && ancien !== @js($espace)) {
                window.location.replace(@js($autrePage) + window.location.hash)
                return
            }
            this.jeton = fragment.get('jeton') ?? ''
            if (window.location.hash) window.history.replaceState(window.history.state, '', window.location.pathname)
        },
    }">
    <a href="{{ $connexion }}"
        class="mb-4 inline-flex items-center gap-2 text-sm font-medium text-bleu underline-offset-4 hover:underline">
        <x-icone nom="arrow-left" /> Retour à la connexion
    </a>

    <h1 class="text-xl font-semibold text-encre">Nouveau mot de passe</h1>

    <div x-show="jeton === ''" x-cloak>
        <p role="alert" class="mt-2 text-base text-encre-2">Ce lien de réinitialisation est incomplet ou a déjà été ouvert. Faites une nouvelle demande.</p>
        <a href="{{ $oubli }}"
            class="mt-5 inline-flex h-9 items-center justify-center rounded-md bg-marine px-3.5 text-base font-medium text-white hover:bg-marine-fonce">
            Demander un nouveau lien
        </a>
    </div>

    <div x-show="jeton !== ''">
        <p class="mt-1 mb-6 text-base text-discret">Espace {{ $espace }}. Confirmez l’adresse de votre compte, puis choisissez un nouveau mot de passe.</p>

        <form x-on:submit.prevent="$wire.enregistrer(jeton)" novalidate class="space-y-4">
            @if ($refus)
                <x-alerte ton="danger">
                    {{ $refus }}
                    <x-slot:action>
                        <a href="{{ $oubli }}"
                            class="inline-flex h-8 items-center rounded-md border border-bord bg-surface px-2.5 text-sm font-medium text-encre hover:bg-marine-50">Nouveau lien</a>
                    </x-slot:action>
                </x-alerte>
            @endif

            <x-saisie libelle="Adresse e-mail du compte" type="email" wire:model="email" autocomplete="username" inputmode="email" requis />
            <x-nouveau-mot-de-passe libelle="Nouveau mot de passe" />

            <x-bouton type="submit" variante="primaire" class="w-full" cible="enregistrer">Enregistrer le nouveau mot de passe</x-bouton>
        </form>
    </div>
</div>
