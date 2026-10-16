@props(['libelle' => null, 'type' => 'checkbox'])
{{-- Case à cocher ou bouton radio natif. Avec un libellé, l'ensemble est cliquable. --}}
@if ($libelle !== null || $slot->isNotEmpty())
    <label class="inline-flex cursor-pointer items-start gap-2 text-base text-encre">
        <input type="{{ $type }}" {{ $attributes->class('case mt-0.5') }} />
        <span>{{ $libelle ?? $slot }}</span>
    </label>
@else
    <input type="{{ $type }}" {{ $attributes->class('case') }} />
@endif
