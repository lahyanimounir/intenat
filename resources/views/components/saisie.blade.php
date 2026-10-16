@props(['libelle' => null, 'nom' => null, 'aide' => null, 'requis' => false, 'taille' => 'md'])
@php
    // La clé d'erreur et l'identifiant se déduisent de `wire:model` : inutile de les répéter.
    $cle = $nom ?? $attributes->whereStartsWith('wire:model')->first();
    $id = $attributes->get('id') ?? ($cle ? 'c-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $cle)) : null);
    $message = $cle ? $errors->first($cle) : null;
    $decrit = $message ? "{$id}-erreur" : ($aide && $libelle ? "{$id}-aide" : null);
    $hauteur = $taille === 'sm' ? 'h-8 text-sm' : 'h-9';
@endphp
<x-champ :libelle="$libelle" :pour="$id" :erreur="$message" :aide="$aide" :requis="$requis" :class="$libelle ? $attributes->get('class') : null">
    <input id="{{ $id }}" @if ($message) aria-invalid="true" @endif @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($requis) aria-required="true" @endif
        {{ $attributes->except($libelle ? ['class', 'id'] : ['id'])->merge(['type' => 'text'])->class(['champ', $hauteur]) }} />
</x-champ>
