{{--
    Cadre commun des écrans hors session : connexion, inscription, récupération de compte.
    Un panneau institutionnel sobre, et le contenu. `$large` élargit la colonne pour les parcours en étapes.
--}}
<x-squelette :titre="$title ?? null">
    <div class="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        <aside class="relative hidden flex-col justify-between bg-marine px-12 py-10 text-white lg:flex">
            <div class="flex items-center gap-3">
                <span aria-hidden="true" class="flex size-9 items-center justify-center rounded-md bg-white/10">
                    <svg viewBox="0 0 32 32" class="size-5" fill="currentColor"><path d="M9 22V12.2L16 8l7 4.2V22h-4.6v-5.4h-4.8V22z" /></svg>
                </span>
                <p class="text-lg font-semibold tracking-wide">INTERNAT</p>
            </div>

            <div class="max-w-md">
                <p class="text-2xl leading-snug font-semibold">Gestion des Maisons des Stagiaires</p>
                <p class="mt-3 text-md leading-relaxed text-white/75">
                    Préinscription, sélection, hébergement, paiements et restauration : un seul outil pour les stagiaires et pour le personnel des établissements.
                </p>
                <ul class="mt-8 space-y-2.5 text-base text-white/80">
                    @foreach (['Accès limité au périmètre de chaque compte', 'Décisions tracées et motivées', 'Documents conservés en espace privé'] as $engagement)
                        <li class="flex items-center gap-2.5">
                            <x-icone nom="shield-check" trait="1.75" class="text-white/60" />
                            {{ $engagement }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <p class="text-sm text-white/50">Office de la Formation Professionnelle et de la Promotion du Travail</p>
        </aside>

        <main class="flex flex-col items-center justify-center px-5 py-10">
            <div @class(['w-full', ($large ?? false) ? 'max-w-2xl' : 'max-w-sm'])>
                <div class="mb-7 lg:hidden">
                    <p class="text-lg font-semibold tracking-wide text-marine">INTERNAT</p>
                    <p class="text-sm text-discret">Gestion des Maisons des Stagiaires</p>
                </div>
                {{ $slot }}
            </div>
        </main>
    </div>
</x-squelette>
