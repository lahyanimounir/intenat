@props(['titre', 'description' => null, 'actions' => null])
<header {{ $attributes->class('flex flex-wrap items-start justify-between gap-3 border-b border-bord-doux px-5 py-3.5') }}>
    <div class="min-w-0">
        <h2 class="text-md font-semibold text-encre">{{ $titre }}</h2>
        @if ($description)<p class="mt-0.5 text-sm text-discret">{{ $description }}</p>@endif
    </div>
    @if ($actions)<div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>@endif
</header>
