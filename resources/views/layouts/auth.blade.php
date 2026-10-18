{{--
    Cadre commun des écrans hors session : connexion, inscription, récupération de compte.
    Contenu centré sur toute la largeur. `$large` élargit la colonne pour les parcours en étapes.
--}}
<x-squelette :titre="$title ?? null">
    <div class="flex min-h-dvh flex-col items-center px-5 py-10">
        <div @class(['w-full', ($large ?? false) ? 'max-w-3xl' : 'max-w-md'])>
            <div class="mb-7 flex items-center gap-3">
                <span aria-hidden="true" class="flex size-9 items-center justify-center rounded-md bg-marine text-white">
                    <svg viewBox="0 0 32 32" class="size-5" fill="currentColor"><path d="M9 22V12.2L16 8l7 4.2V22h-4.6v-5.4h-4.8V22z" /></svg>
                </span>
                <div>
                    <p class="text-lg font-semibold tracking-wide text-marine">INTERNAT</p>
                    <p class="text-sm text-discret">Gestion des Maisons des Stagiaires</p>
                </div>
            </div>
            {{ $slot }}
        </div>
    </div>
</x-squelette>
