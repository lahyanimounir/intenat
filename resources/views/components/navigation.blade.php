@props(['sections', 'reductible' => false])
{{-- Navigation principale. `reductible` : sur grand écran, la barre peut se replier sur ses icônes. --}}
<nav aria-label="Navigation principale" class="defilement-fin flex-1 overflow-y-auto px-2.5 py-3">
    @foreach ($sections as $section)
        <div @class(['mt-4' => ! $loop->first])>
            @if ($section['titre'])
                <p class="mb-1 px-2.5 text-2xs font-medium tracking-wider text-discret uppercase" @if ($reductible) x-show="! reduit" @endif>{{ $section['titre'] }}</p>
                @if ($reductible)<div aria-hidden="true" class="mx-2 mb-3 h-px bg-bord-doux" x-show="reduit" x-cloak></div>@endif
            @endif
            <ul class="space-y-px">
                @foreach ($section['entrees'] as $entree)
                    <li>
                        <a href="{{ url($entree['chemin']) }}" wire:navigate @if ($entree['actif']) aria-current="page" @endif
                            @if ($reductible) x-bind:title="reduit ? @js($entree['libelle']) : null" x-bind:class="reduit && 'justify-center px-0!'" @endif
                            @class([
                                'group relative flex h-8.5 items-center gap-2.5 rounded-md px-2.5 text-base transition-colors',
                                'bg-marine-50 font-medium text-marine' => $entree['actif'],
                                'text-encre-2 hover:bg-marine-50/70 hover:text-encre' => ! $entree['actif'],
                            ])>
                            @if ($entree['actif'])<span aria-hidden="true" class="absolute top-1.5 bottom-1.5 -left-2.5 w-[3px] rounded-r-sm bg-marine"></span>@endif
                            <x-icone :nom="$entree['icone']" trait="1.75" @class(['text-marine' => $entree['actif'], 'text-discret group-hover:text-encre-2' => ! $entree['actif']]) />
                            <span class="truncate" @if ($reductible) x-bind:class="reduit && 'sr-only'" @endif>{{ $entree['libelle'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
