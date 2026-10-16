@props(['etat' => null, 'ton' => null])
{{-- Pastille d'un état métier : une énumération dotée d'un libellé. --}}
@if ($etat === null)
    <span class="text-discret">—</span>
@else
    <x-badge :ton="$ton ?? \App\Support\Affichage\Tons::de($etat)" point {{ $attributes }}>{{ $etat->libelle() }}</x-badge>
@endif
