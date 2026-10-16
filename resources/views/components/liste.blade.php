@props(['libelle' => null, 'nom' => null, 'aide' => null, 'requis' => false, 'options' => null, 'vide' => null, 'taille' => 'md'])
@php
    $cle = $nom ?? $attributes->whereStartsWith('wire:model')->first();
    $id = $attributes->get('id') ?? ($cle ? 'c-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $cle)) : null);
    $message = $cle ? $errors->first($cle) : null;
    $decrit = $message ? "{$id}-erreur" : ($aide && $libelle ? "{$id}-aide" : null);
    $hauteur = $taille === 'sm' ? 'h-8 text-sm' : 'h-9';
@endphp
{{-- Liste déroulante native, stylée : clavier, lecteurs d'écran et mobile fonctionnent sans surcouche. --}}
{{-- `options` : tableau valeur => libellé, ou liste de ['valeur' => …, 'libelle' => …] (énumérations). --}}
<x-champ :libelle="$libelle" :pour="$id" :erreur="$message" :aide="$aide" :requis="$requis" :class="$libelle ? $attributes->get('class') : null">
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
</x-champ>
