@props([
    'variante' => 'secondaire',
    'taille' => 'md',
    'href' => null,
    'type' => 'button',
    'icone' => null,
    'iconeFin' => null,
    // Action Livewire dont on attend la fin : le bouton se désactive et affiche un indicateur.
    'cible' => null,
    // Faux pour un téléchargement ou un lien externe : la navigation Livewire ne s'applique pas.
    'navigation' => true,
])
@php
    $variantes = [
        'primaire' => 'bg-marine text-white hover:bg-marine-fonce active:bg-marine-fonce',
        'secondaire' => 'border border-bord bg-surface text-encre hover:bg-marine-50 hover:border-marine-200',
        'discret' => 'text-encre-2 hover:bg-marine-50 hover:text-encre',
        'danger' => 'bg-danger text-white hover:bg-[#93190f]',
        'danger-discret' => 'border border-danger-bord bg-surface text-danger hover:bg-danger-fond',
        'lien' => 'text-bleu underline-offset-4 hover:underline',
    ];
    $tailles = ['sm' => 'h-8 px-2.5 text-sm', 'md' => 'h-9 px-3.5 text-base', 'icone' => 'size-8'];
    $classes = 'inline-flex shrink-0 items-center justify-center gap-2 rounded-md font-medium whitespace-nowrap transition-colors select-none disabled:cursor-not-allowed disabled:opacity-55 '
        .$variantes[$variante].' '.($variante === 'lien' ? ($taille === 'sm' ? 'text-sm' : 'text-base') : $tailles[$taille]);
@endphp
@if ($href !== null)
    <a href="{{ $href }}" @if ($navigation) wire:navigate @endif {{ $attributes->class($classes) }}>
        @if ($icone)<x-icone :nom="$icone" />@endif
        {{ $slot }}
        @if ($iconeFin)<x-icone :nom="$iconeFin" />@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}
        @if ($cible) wire:loading.attr="disabled" wire:target="{{ $cible }}" @endif>
        @if ($cible)
            <x-rotation wire:loading wire:target="{{ $cible }}" />
            @if ($icone)<x-icone :nom="$icone" wire:loading.remove wire:target="{{ $cible }}" />@endif
        @elseif ($icone)
            <x-icone :nom="$icone" />
        @endif
        {{ $slot }}
        @if ($iconeFin)<x-icone :nom="$iconeFin" />@endif
    </button>
@endif
