@props(['libelle', 'description' => null, 'cochee' => false])
@php $id = $attributes->get('id') ?? 'd-'.\Illuminate\Support\Str::slug((string) $attributes->whereStartsWith('wire:model')->first()); @endphp
{{-- Case à cocher avec libellé et description, utilisée pour les déclarations du dossier. --}}
<div @class([
    'flex gap-3 rounded-md border px-3.5 py-3 transition-colors',
    'border-marine-200 bg-marine-50' => $cochee,
    'border-bord bg-surface' => ! $cochee,
    'opacity-70' => $attributes->get('disabled'),
])>
    <input type="checkbox" id="{{ $id }}" {{ $attributes->except('id')->class('case mt-0.5') }} />
    <div class="min-w-0">
        <label for="{{ $id }}" class="block cursor-pointer text-base font-medium text-encre">{{ $libelle }}</label>
        @if ($description)<p class="mt-0.5 text-sm text-discret">{{ $description }}</p>@endif
    </div>
</div>
