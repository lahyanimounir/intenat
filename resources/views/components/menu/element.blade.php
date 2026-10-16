@props(['href' => null, 'icone' => null, 'danger' => false, 'navigation' => true])
@php
    $classes = 'flex w-full items-center gap-2 rounded-sm px-2.5 py-1.5 text-left text-base select-none hover:bg-marine-50 focus-visible:bg-marine-50 disabled:opacity-50 '
        .($danger ? 'text-danger' : 'text-encre');
@endphp
@if ($href)
    <a href="{{ $href }}" role="menuitem" @if ($navigation) wire:navigate @endif x-on:click="ouvert = false" {{ $attributes->class($classes) }}>
        @if ($icone)<x-icone :nom="$icone" @class(['text-danger' => $danger, 'text-discret' => ! $danger]) />@endif
        {{ $slot }}
    </a>
@else
    <button type="submit" role="menuitem" x-on:click="ouvert = false" {{ $attributes->class($classes) }}>
        @if ($icone)<x-icone :nom="$icone" @class(['text-danger' => $danger, 'text-discret' => ! $danger]) />@endif
        {{ $slot }}
    </button>
@endif
