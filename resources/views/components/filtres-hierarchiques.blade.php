@props(['filtre', 'region' => '', 'province' => '', 'complexe' => '', 'etablissement' => ''])
{{-- Filtres de la hiérarchie administrative (trait AvecHierarchie) : un niveau ne s'ouvre qu'une fois son parent choisi. --}}
<x-filtre libelle="Région" wire:model.live="region" :options="$filtre['regions']" tous="toutes" :actif="$region !== ''" />
<x-filtre libelle="Province" wire:model.live="province" :options="$filtre['provinces']" tous="toutes" :actif="$province !== ''"
    :disabled="$region === ''" wire:key="filtre-province-{{ $region }}" />
<x-filtre libelle="Complexe / CMC" wire:model.live="complexe" :options="$filtre['complexes']" :actif="$complexe !== ''"
    :disabled="$province === ''" wire:key="filtre-complexe-{{ $province }}" />
<x-filtre libelle="Établissement" wire:model.live="etablissement" :options="$filtre['etablissements']" :actif="$etablissement !== ''"
    :disabled="$complexe === ''" wire:key="filtre-etablissement-{{ $complexe }}" />
