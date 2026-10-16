@props(['libelle' => 'Chargement…'])
<div role="status" aria-live="polite" {{ $attributes->class('flex items-center justify-center gap-2.5 py-12 text-sm text-discret') }}>
    <x-rotation />
    <span>{{ $libelle }}</span>
</div>
