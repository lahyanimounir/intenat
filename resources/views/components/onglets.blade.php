@props(['libelle' => 'Sections'])
<div role="tablist" aria-label="{{ $libelle }}" {{ $attributes->class('defilement-fin flex gap-5 overflow-x-auto overflow-y-hidden border-b border-bord') }}>
    {{ $slot }}
</div>
