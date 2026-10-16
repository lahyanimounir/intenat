@props(['titre', 'icone' => 'inbox', 'action' => null])
<div {{ $attributes->class('flex flex-col items-center px-6 py-12 text-center') }}>
    <span class="mb-3 flex size-10 items-center justify-center rounded-full bg-marine-50 text-marine">
        <x-icone :nom="$icone" class="size-5" trait="1.75" />
    </span>
    <p class="text-md font-medium text-encre">{{ $titre }}</p>
    @if ($slot->isNotEmpty())<div class="mt-1 max-w-md text-base text-discret">{{ $slot }}</div>@endif
    @if ($action)<div class="mt-4">{{ $action }}</div>@endif
</div>
