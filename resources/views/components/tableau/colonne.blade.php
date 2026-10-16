@props(['cle' => null, 'droite' => false])
@aware(['tri' => null, 'sens' => 'asc'])
@php $actif = $cle !== null && $tri === $cle; @endphp
{{-- En-tête de colonne. Avec `cle`, la colonne est triable : le composant Livewire expose `trier(cle)`. --}}
<th scope="col" @if ($actif) aria-sort="{{ $sens === 'asc' ? 'ascending' : 'descending' }}" @endif
    {{ $attributes->class(['border-b border-bord px-3 py-2.5 text-xs font-medium whitespace-nowrap text-discret', 'text-right' => $droite]) }}>
    @if ($cle)
        <button type="button" class="-mx-1 inline-flex items-center gap-1 rounded-sm px-1 hover:text-encre" wire:click="trier('{{ $cle }}')">
            {{ $slot }}
            @if ($actif)
                <x-icone :nom="$sens === 'asc' ? 'arrow-up' : 'arrow-down'" class="size-3.5" />
            @else
                <x-icone nom="arrow-up-down" class="size-3.5 opacity-50" />
            @endif
        </button>
    @else
        {{ $slot }}
    @endif
</th>
