@props(['nom', 'trait' => 2])
{{-- Icône au trait (Lucide). Décorative : le texte voisin porte le sens. --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $trait }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
    {{ $attributes->class(['shrink-0', 'size-4' => ! str_contains((string) $attributes->get('class'), 'size-')]) }}>{!! \App\Support\Affichage\Icones::trace($nom) !!}</svg>
