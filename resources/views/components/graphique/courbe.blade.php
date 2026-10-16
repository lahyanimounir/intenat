@props([
    // Points de la courbe : [['nom' => 'janvier', 'valeur' => 42], …]
    'donnees',
    // Nom de la grandeur tracée, pour l'infobulle et le résumé.
    'serie',
    'hauteur' => 220,
    'couleur' => \App\Support\Affichage\Graphique::MARINE,
])
@php
    use App\Support\Affichage\Format;
    use App\Support\Affichage\Graphique;

    $donnees = collect($donnees)->values();
    $echelle = Graphique::echelle($donnees->max('valeur') ?? 0);
    $n = max(1, $donnees->count());
    // Chaque point est centré dans sa colonne : abscisse en pourcentage de la largeur du tracé.
    $x = fn (int $i) => round(($i + 0.5) / $n * 100, 3);
    $y = fn ($valeur) => round(100 - Graphique::hauteur($valeur, $echelle['max']), 3);
    $trace = $donnees->map(fn ($d, $i) => $x($i).','.$y($d['valeur']))->implode(' ');
    $resume = $donnees->map(fn ($d) => $d['nom'].' : '.Format::nombre($d['valeur']).' '.mb_strtolower($serie))->implode(' ; ');
@endphp
{{-- Courbe rendue par le serveur : le tracé s'étire avec la carte, les étiquettes restent nettes. --}}
<figure {{ $attributes->class('m-0') }}>
    <figcaption class="sr-only">{{ $resume }}</figcaption>
    <div aria-hidden="true" class="flex" style="height: {{ (int) $hauteur }}px" x-data="{ actif: null }">
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
                <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none">
                    <polyline points="{{ $trace }}" fill="none" stroke="{{ $couleur }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                </svg>
                @foreach ($donnees as $i => $point)
                    <span class="absolute size-2 -translate-x-1/2 translate-y-1/2 rounded-full transition-transform" x-bind:class="actif === {{ $i }} && 'scale-150'"
                        style="left: {{ $x($i) }}%; bottom: {{ Graphique::hauteur($point['valeur'], $echelle['max']) }}%; background: {{ $couleur }}"></span>
                @endforeach
            </div>
            <div class="absolute inset-0 flex">
                @foreach ($donnees as $i => $point)
                    <div class="relative flex min-w-0 flex-1 flex-col justify-end" x-on:mouseenter="actif = {{ $i }}" x-on:mouseleave="actif = null">
                        <span x-show="actif === {{ $i }}" x-cloak class="absolute top-0 bottom-6 left-1/2 border-l border-[#c9d5e5]"></span>
                        <span class="block h-6 truncate px-0.5 pt-1.5 text-center text-xs leading-none text-discret">{{ $point['nom'] }}</span>
                        <div x-show="actif === {{ $i }}" x-cloak
                            @class(['pointer-events-none absolute top-1 z-10 w-max max-w-56 rounded-md border border-bord bg-surface px-2.5 py-2 text-left text-sm shadow-md', 'left-1/2 ml-2' => $i < $n / 2, 'right-1/2 mr-2' => $i >= $n / 2])>
                            <p class="mb-0.5 font-semibold text-encre">{{ $point['titre'] ?? $point['nom'] }}</p>
                            <p class="text-encre-2">{{ $serie }} : <span class="chiffres font-medium text-encre">{{ Format::nombre($point['valeur']) }}</span></p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</figure>
