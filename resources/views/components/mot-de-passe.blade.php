@props(['libelle' => null, 'nom' => null, 'aide' => null, 'requis' => false])
@php
    $cle = $nom ?? $attributes->whereStartsWith('wire:model')->first();
    $id = $attributes->get('id') ?? ($cle ? 'c-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $cle)) : null);
    $message = $cle ? $errors->first($cle) : null;
    $decrit = $message ? "{$id}-erreur" : ($aide && $libelle ? "{$id}-aide" : null);
@endphp
{{-- Mot de passe, avec bouton pour l'afficher le temps de le vérifier. --}}
<x-champ :libelle="$libelle" :pour="$id" :erreur="$message" :aide="$aide" :requis="$requis" :class="$libelle ? $attributes->get('class') : null">
    <div class="relative" x-data="{ visible: false }">
        <input id="{{ $id }}" x-bind:type="visible ? 'text' : 'password'" type="password"
            @if ($message) aria-invalid="true" @endif @if ($decrit) aria-describedby="{{ $decrit }}" @endif @if ($requis) aria-required="true" @endif
            {{ $attributes->except(['class', 'id', 'type'])->class('champ h-9 pr-10') }} />
        <button type="button" x-on:click="visible = ! visible" x-bind:aria-pressed="visible.toString()"
            x-bind:aria-label="visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" aria-label="Afficher le mot de passe"
            class="absolute top-1/2 right-1.5 flex size-7 -translate-y-1/2 items-center justify-center rounded-sm text-discret hover:text-encre">
            <x-icone nom="eye" x-show="! visible" />
            <x-icone nom="eye-off" x-show="visible" x-cloak />
        </button>
    </div>
</x-champ>
