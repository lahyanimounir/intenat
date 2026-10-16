{{-- Actions attendues de la personne connectée. `lignes` : liste de [libellé, nombre, adresse] ; seules celles dont le nombre est positif s'affichent. --}}
@php $actives = collect($lignes)->filter(fn (array $ligne) => $ligne[1] > 0); @endphp
<x-carte>
    <x-carte.entete titre="À traiter" description="Ce qui attend une action de votre part." />
    @if ($actives->isEmpty())
        <x-vide titre="Rien à traiter pour l’instant" icone="circle-check" class="py-8" />
    @else
        <ul>
            @foreach ($actives as [$libelle, $nombre, $adresse])
                <li class="border-b border-bord-doux last:border-0">
                    <a href="{{ $adresse }}" wire:navigate class="group flex items-center gap-3 px-5 py-3 hover:bg-marine-50/60">
                        <span class="chiffres flex h-6 min-w-6 items-center justify-center rounded-sm bg-alerte-fond px-1.5 text-sm font-semibold text-alerte">{{ $nombre }}</span>
                        <span class="flex-1 text-base text-encre">{{ $libelle }}</span>
                        <x-icone nom="arrow-right" class="size-4 text-marine-200 transition-colors group-hover:text-marine" />
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-carte>
