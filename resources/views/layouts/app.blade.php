@php
    use App\Models\Stagiaire;
    use App\Support\Affichage\Format;
    use App\Support\Affichage\Menu;

    // L'espace affiché est celui du compte connecté : personnel ou stagiaire.
    $compte = auth('stagiaire')->user() ?? auth('web')->user();
    $estStagiaire = $compte instanceof Stagiaire;
    $sections = $estStagiaire ? Menu::stagiaire($compte) : Menu::personnel($compte);
    $role = $estStagiaire ? 'Stagiaire' : $compte->roleCode()?->libelle();
    $perimetre = $estStagiaire
        ? $compte->etablissement?->nom_efp
        : ($compte->internat?->nom ?? $compte->etablissement?->nom_efp ?? $compte->complexe?->nom ?? $compte->region?->nom ?? 'Périmètre national');
    $nom = $compte->nomComplet() ?: $compte->email;
@endphp
<x-squelette :titre="$title ?? null">
    <div class="flex min-h-dvh flex-col" x-data="{ reduit: $persist(false).as('internat.menu.reduit'), mobile: false }">
        <a href="#contenu" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-marine focus:px-3 focus:py-2 focus:text-white">Aller au contenu</a>

        @if (config('internat.mode_demo'))
            <div role="note" class="non-imprime border-b border-alerte-bord bg-alerte-fond px-4 py-1.5 text-center text-sm text-alerte">
                <strong class="font-semibold">Démonstration</strong> — données synthétiques. Aucune valeur administrative.
            </div>
        @endif

        <div class="flex min-h-0 flex-1">
            {{-- Barre latérale (bureau) --}}
            <aside class="non-imprime sticky top-0 hidden h-dvh w-62 shrink-0 flex-col border-r border-bord bg-surface transition-[width] duration-150 lg:flex" x-bind:class="reduit && 'w-15!'">
                <div class="flex h-14 shrink-0 items-center border-b border-bord-doux px-4" x-bind:class="reduit && 'justify-center px-0!'">
                    <x-marque reductible />
                </div>
                <x-navigation :sections="$sections" reductible />
                <div class="border-t border-bord-doux p-2.5">
                    <button type="button" x-on:click="reduit = ! reduit" x-bind:aria-expanded="(! reduit).toString()" x-bind:aria-label="reduit ? 'Déplier le menu' : 'Replier le menu'"
                        class="inline-flex h-8 w-full items-center gap-2 rounded-md px-2.5 text-sm font-medium text-encre-2 transition-colors hover:bg-marine-50 hover:text-encre"
                        x-bind:class="reduit && 'justify-center px-0!'">
                        <x-icone nom="chevrons-left" x-show="! reduit" />
                        <x-icone nom="chevrons-right" x-show="reduit" x-cloak />
                        <span x-show="! reduit">Replier le menu</span>
                    </button>
                </div>
            </aside>

            {{-- Barre latérale (mobile) --}}
            <div class="fixed inset-0 z-40 lg:hidden" x-show="mobile" x-cloak x-on:keydown.escape.window="mobile = false">
                <button type="button" aria-label="Fermer le menu" class="absolute inset-0 bg-encre/35" x-on:click="mobile = false"></button>
                <aside class="relative flex h-full w-72 max-w-[85vw] flex-col border-r border-bord bg-surface shadow-md" x-trap.noscroll="mobile">
                    <div class="flex h-14 items-center justify-between border-b border-bord-doux px-4">
                        <x-marque />
                        <x-bouton variante="discret" taille="icone" aria-label="Fermer le menu" x-on:click="mobile = false"><x-icone nom="x" /></x-bouton>
                    </div>
                    <x-navigation :sections="$sections" />
                </aside>
            </div>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="non-imprime sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b border-bord bg-surface/95 px-4 backdrop-blur-sm lg:px-6">
                    <x-bouton variante="discret" taille="icone" class="lg:hidden" aria-label="Ouvrir le menu" x-on:click="mobile = true"><x-icone nom="menu" /></x-bouton>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-discret">
                            <span class="font-medium text-encre-2">{{ $role }}</span>@if ($perimetre)<span class="hidden sm:inline"> · {{ $perimetre }}</span>@endif
                        </p>
                    </div>

                    @if (! $estStagiaire && $compte->can('demandes.consulter'))
                        <form role="search" method="get" action="{{ url('/dossiers') }}" class="relative hidden md:block">
                            <x-icone nom="search" class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-discret" />
                            <input type="search" name="recherche" aria-label="Rechercher un dossier" placeholder="Rechercher un dossier, un CEF…"
                                class="h-8 w-64 rounded-md border border-bord bg-surface-2 pr-3 pl-8 text-sm placeholder:text-discret/80 hover:border-marine-200 focus-visible:bg-surface" />
                        </form>
                    @endif

                    <livewire:cloche />

                    <x-menu largeur="w-60">
                        <x-slot:declencheur>
                            <button type="button" class="flex items-center gap-2 rounded-md py-1 pr-1.5 pl-1 hover:bg-marine-50" aria-label="Compte de {{ $nom }}" aria-haspopup="menu">
                                <span aria-hidden="true" class="flex size-7 items-center justify-center rounded-full bg-marine-100 text-xs font-semibold text-marine">{{ Format::initiales($nom) }}</span>
                                <span class="hidden max-w-40 truncate text-sm font-medium text-encre sm:block">{{ $nom }}</span>
                            </button>
                        </x-slot:declencheur>
                        <div class="px-2.5 py-2">
                            <p class="truncate text-base font-medium">{{ $nom }}</p>
                            <p class="truncate text-sm text-discret">{{ $compte->email }}</p>
                            <p class="mt-1.5 text-xs text-discret">{{ $role }}@if ($perimetre) · {{ $perimetre }}@endif</p>
                        </div>
                        <div class="my-1 h-px bg-bord-doux"></div>
                        <form method="post" action="{{ route('deconnexion') }}">
                            @csrf
                            <x-menu.element type="submit" icone="log-out">Se déconnecter</x-menu.element>
                        </form>
                    </x-menu>
                </header>

                <main id="contenu" tabindex="-1" class="mx-auto w-full max-w-[1400px] flex-1 px-4 py-6 focus:outline-none lg:px-8 lg:py-7">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </div>
</x-squelette>
