@props(['titre', 'description' => null, 'fil' => [], 'actions' => null, 'marque' => null])
{{-- Titre de page, fil d'Ariane, description et actions principales. `fil` : liste de [libellé, adresse ou null]. --}}
<div class="mb-6">
    @if (count($fil) > 0)
        <nav aria-label="Fil d'Ariane" class="mb-2">
            <ol class="flex flex-wrap items-center gap-1 text-sm text-discret">
                @foreach ($fil as $etape)
                    <li class="flex items-center gap-1">
                        @if (! $loop->first)<x-icone nom="chevron-right" class="size-3.5 text-marine-200" />@endif
                        @if ($etape[1] ?? null)
                            <a href="{{ $etape[1] }}" wire:navigate class="rounded-sm hover:text-encre hover:underline">{{ $etape[0] }}</a>
                        @else
                            <span aria-current="page" class="text-encre-2">{{ $etape[0] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        {{-- Le bloc du titre cède la place aux actions : elles restent à droite tant que la largeur le permet. --}}
        <div class="min-w-0 flex-1 basis-80">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-semibold text-encre">{{ $titre }}</h1>
                {{ $marque }}
            </div>
            @if ($description)<p class="mt-1 max-w-3xl text-base text-discret">{{ $description }}</p>@endif
        </div>
        @if ($actions)<div class="non-imprime flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>@endif
    </div>
</div>
