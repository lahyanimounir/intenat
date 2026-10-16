{{-- Les chiffres se rafraîchissent d'eux-mêmes toutes les cinq minutes. --}}
<div wire:poll.300s>
    <x-page.entete :titre="$titre" :description="$description" />

    @switch($type)
        @case('administrateur')
            @include('livewire.personnel.tableaux.administrateur')
            @break
        @case('gestionnaire')
            @include('livewire.personnel.tableaux.gestionnaire')
            @break
        @case('chef_pole')
            @include('livewire.personnel.tableaux.chef-de-pole')
            @break
        @case('restauration')
            @include('livewire.personnel.tableaux.restauration')
            @break
        @case('directeur')
            @include('livewire.personnel.tableaux.consolide', ['intitule' => 'Établissements du complexe'])
            @break
        @case('direction_regionale')
            @include('livewire.personnel.tableaux.consolide', ['intitule' => 'Établissements de la région'])
            @break
        @case('direction_centrale')
            @include('livewire.personnel.tableaux.direction-centrale')
            @break
        @default
            <x-carte><x-vide titre="Aucun tableau de bord pour ce compte" icone="layout-dashboard">Ce compte n’a pas de rôle : adressez-vous à l’administrateur de l’application.</x-vide></x-carte>
    @endswitch
</div>
