@props([
    // Liste de catégories : ['nom' => 'A', 'cle1' => 12, 'cle2' => 7, …]. `titre` (facultatif) remplace `nom` dans l'infobulle.
    'donnees',
    // Séries tracées : [['cle' => 'cle1', 'nom' => 'Occupés', 'couleur' => '#183153'], …]
    'series',
    'empile' => false,
    'hauteur' => 240,
])
@php
    use App\Support\Affichage\Format;
    use App\Support\Affichage\Graphique;

    $donnees = collect($donnees)->values();
    $maximum = $donnees->max(fn ($d) => $empile
        ? collect($series)->sum(fn ($s) => (float) ($d[$s['cle']] ?? 0))
        : collect($series)->max(fn ($s) => (float) ($d[$s['cle']] ?? 0))) ?? 0;
    $echelle = Graphique::echelle($maximum);
    $resume = $donnees->map(fn ($d) => ($d['titre'] ?? $d['nom']).' : '.collect($series)->map(fn ($s) => $s['nom'].' '.Format::nombre($d[$s['cle']] ?? 0))->implode(', '))->implode(' ; ');
    // Au-delà d'une vingtaine de catégories, une étiquette sur deux suffit à lire l'axe.
    $saut = $donnees->count() > 20 ? 2 : 1;
@endphp
{{-- Graphique en barres rendu par le serveur. Le résumé textuel est lu par les lecteurs d'écran ; le tracé leur est masqué. --}}
<figure {{ $attributes->class('m-0') }}>
    <figcaption class="sr-only">{{ $resume }}</figcaption>
    <div aria-hidden="true" x-data="{ actif: null }">
        <div class="flex" style="height: {{ (int) $hauteur }}px">
            <div class="relative w-9 shrink-0 text-right text-xs text-discret" style="margin-bottom: 1.5rem">
                @foreach ($echelle['graduations'] as $graduation)
                    <span class="chiffres absolute right-2 translate-y-1/2 leading-none" style="bottom: {{ Graphique::hauteur($graduation, $echelle['max']) }}%">{{ Format::nombre($graduation) }}</span>
                @endforeach
            </div>
            <div class="relative min-w-0 flex-1">
                <div class="absolute inset-x-0 top-0 bottom-6 border-b border-[#edf0f4]">
                    @foreach ($echelle['graduations'] as $graduation)
                        @if ($graduation > 0)<span class="absolute inset-x-0 border-t border-[#edf0f4]" style="bottom: {{ Graphique::hauteur($graduation, $echelle['max']) }}%"></span>@endif
                    @endforeach
                </div>
                <div class="absolute inset-0 flex">
                    @foreach ($donnees as $i => $categorie)
                        <div class="relative flex min-w-0 flex-1 flex-col" x-on:mouseenter="actif = {{ $i }}" x-on:mouseleave="actif = null"
                            x-bind:class="actif === {{ $i }} && 'bg-[#f3f6fa]'">
                            <div @class(['flex min-h-0 flex-1 items-end justify-center px-[12%]', 'gap-[3px]' => ! $empile])>
                                @if ($empile)
                                    <div class="flex h-full w-full max-w-11 flex-col-reverse overflow-hidden">
                                        @foreach ($series as $serie)
                                            <span class="block w-full shrink-0 first:rounded-none last:rounded-t-[3px]" style="height: {{ Graphique::hauteur($categorie[$serie['cle']] ?? 0, $echelle['max']) }}%; background: {{ $serie['couleur'] }}"></span>
                                        @endforeach
                                    </div>
                                @else
                                    @foreach ($series as $serie)
                                        <span class="block w-full max-w-8 rounded-t-[3px]" style="height: {{ Graphique::hauteur($categorie[$serie['cle']] ?? 0, $echelle['max']) }}%; background: {{ $serie['couleur'] }}"></span>
                                    @endforeach
                                @endif
                            </div>
                            <span class="block h-6 truncate px-0.5 pt-1.5 text-center text-xs leading-none text-discret">{{ $i % $saut === 0 ? $categorie['nom'] : '' }}</span>

                            <div x-show="actif === {{ $i }}" x-cloak
                                @class(['pointer-events-none absolute top-1 z-10 w-max max-w-56 rounded-md border border-bord bg-surface px-2.5 py-2 text-left text-sm shadow-md', 'left-1/2' => $i < $donnees->count() / 2, 'right-1/2' => $i >= $donnees->count() / 2])>
                                <p class="mb-0.5 font-semibold text-encre">{{ $categorie['titre'] ?? $categorie['nom'] }}</p>
                                @foreach ($series as $serie)
                                    <p class="flex items-center gap-1.5 text-encre-2">
                                        <span class="size-2 shrink-0" style="background: {{ $serie['couleur'] }}"></span>
                                        {{ $serie['nom'] }} : <span class="chiffres font-medium text-encre">{{ Format::nombre($categorie[$serie['cle']] ?? 0) }}</span>
                                    </p>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <ul class="mt-2 flex flex-wrap justify-center gap-x-4 gap-y-1 text-xs text-encre-2">
            @foreach ($series as $serie)
                <li class="flex items-center gap-1.5"><span class="size-2.5" style="background: {{ $serie['couleur'] }}"></span>{{ $serie['nom'] }}</li>
            @endforeach
        </ul>
    </div>
</figure>
