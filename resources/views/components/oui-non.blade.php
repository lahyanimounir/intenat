@props(['valeur' => null, 'oui' => 'Oui', 'non' => 'Non'])
@if ($valeur === null)
    <span class="text-discret">—</span>
@else
    <x-badge :ton="$valeur ? 'succes' : 'neutre'">{{ $valeur ? $oui : $non }}</x-badge>
@endif
