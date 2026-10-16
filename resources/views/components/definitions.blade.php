@props(['colonnes' => 2])
@php $grille = [1 => 'grid-cols-1', 2 => 'grid-cols-1 sm:grid-cols-2', 3 => 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3']; @endphp
<dl {{ $attributes->class(['grid gap-x-6 gap-y-3.5', $grille[$colonnes]]) }}>{{ $slot }}</dl>
