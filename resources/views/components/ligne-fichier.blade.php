@props(['nom', 'detail' => null, 'actions' => null])
{{-- Ligne résumant un fichier déposé, avec ses actions. --}}
<div {{ $attributes->class('flex items-center gap-3 rounded-md border border-bord bg-surface px-3 py-2') }}>
    <x-icone nom="file-text" class="text-discret" trait="1.75" />
    <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-medium text-encre">{{ $nom }}</p>
        @if ($detail)<p class="truncate text-xs text-discret">{{ $detail }}</p>@endif
    </div>
    @if ($actions)<div class="flex shrink-0 items-center gap-1">{{ $actions }}</div>@endif
</div>
