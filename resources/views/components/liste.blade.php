@props(['libelle' => null, 'nom' => null, 'aide' => null, 'requis' => false, 'options' => null, 'vide' => null, 'taille' => 'md', 'recherche' => null])
@php
    $cle = $nom ?? $attributes->whereStartsWith('wire:model')->first();
    $id = $attributes->get('id') ?? ($cle ? 'c-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $cle)) : null);
    $message = $cle ? $errors->first($cle) : null;
    $decrit = $message ? "{$id}-erreur" : ($aide && $libelle ? "{$id}-aide" : null);
    $hauteur = $taille === 'sm' ? 'h-8 text-sm' : 'h-9';

    // Recherche automatique dès que la liste est longue (établissements, internats…) ;
    // les petits selects (genre, niveau…) restent natifs. Surchargable via :recherche.
    $nbOptions = is_countable($options) ? count($options) : 0;
    $recherche = ($recherche ?? ($nbOptions > 3)) && $cle;
    $live = $attributes->whereStartsWith('wire:model.live')->first() !== null;
@endphp
{{-- `options` : tableau valeur => libellé, ou liste de ['valeur' => …, 'libelle' => …] (énumérations). --}}
<x-champ :libelle="$libelle" :pour="$id" :erreur="$message" :aide="$aide" :requis="$requis" :class="$libelle ? $attributes->get('class') : null">
    @if ($recherche)
        {{-- Liste recherchable (Tom Select). Le <select> est ignoré par Livewire ; la valeur passe par $wire. --}}
        <div wire:ignore x-data="selectRecherche(@js($cle), @js($live))">
            <select x-ref="select" id="{{ $id }}" data-placeholder="{{ $vide ?? 'Rechercher…' }}"
                @if ($message) aria-invalid="true" @endif @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($requis) aria-required="true" @endif
                {{ $attributes->whereDoesntStartWith('wire:model')->except(['class', 'id']) }}>
                @if ($vide !== null)<option value="">{{ $vide }}</option>@endif
                @foreach ($options ?? [] as $valeur => $option)
                    @if (is_array($option))
                        <option value="{{ $option['valeur'] }}">{{ $option['libelle'] }}</option>
                    @else
                        <option value="{{ $valeur }}">{{ $option }}</option>
                    @endif
                @endforeach
                {{ $slot }}
            </select>
        </div>
    @else
        {{-- Liste déroulante native, stylée : clavier, lecteurs d'écran et mobile fonctionnent sans surcouche. --}}
        <div class="relative">
            <select id="{{ $id }}" @if ($message) aria-invalid="true" @endif @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($requis) aria-required="true" @endif
                {{ $attributes->except($libelle ? ['class', 'id'] : ['id'])->class(['champ appearance-none pr-8', $hauteur]) }}>
                @if ($vide !== null)<option value="">{{ $vide }}</option>@endif
                @foreach ($options ?? [] as $valeur => $option)
                    @if (is_array($option))
                        <option value="{{ $option['valeur'] }}">{{ $option['libelle'] }}</option>
                    @else
                        <option value="{{ $valeur }}">{{ $option }}</option>
                    @endif
                @endforeach
                {{ $slot }}
            </select>
            <x-icone nom="chevron-down" class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-discret" />
        </div>
    @endif
</x-champ>
