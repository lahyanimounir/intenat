@props(['libelle' => null, 'nom' => null, 'aide' => null, 'requis' => false])
@php
    $cle = $nom ?? $attributes->whereStartsWith('wire:model')->first();
    $id = $attributes->get('id') ?? ($cle ? 'c-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $cle)) : null);
    $message = $cle ? $errors->first($cle) : null;
    $decrit = $message ? "{$id}-erreur" : ($aide && $libelle ? "{$id}-aide" : null);
@endphp
<x-champ :libelle="$libelle" :pour="$id" :erreur="$message" :aide="$aide" :requis="$requis" :class="$libelle ? $attributes->get('class') : null">
    <textarea id="{{ $id }}" @if ($message) aria-invalid="true" @endif @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($requis) aria-required="true" @endif
        {{ $attributes->except($libelle ? ['class', 'id'] : ['id'])->merge(['rows' => 3])->class('champ min-h-20 py-2 leading-relaxed') }}>{{ $slot }}</textarea>
</x-champ>
