@props([
    // Fichier déjà choisi (téléversement temporaire Livewire), pour l'afficher sous la zone de dépôt.
    'fichier' => null,
    'accept' => '.pdf,.jpg,.jpeg,.png',
    'libelle' => 'Choisir un fichier',
    'indication' => null,
    'compact' => false,
])
@php
    $modele = (string) $attributes->whereStartsWith('wire:model')->first();
    $id = $attributes->get('id') ?? 'f-'.\Illuminate\Support\Str::slug(str_replace(['.', '_'], '-', $modele));
    $extensions = mb_strtoupper(str_replace(['.', ','], ['', ', '], $accept));
    $indication ??= $extensions.' · '.round(config('internat.documents.taille_max_ko') / 1024).' Mo maximum';
@endphp
{{--
    Sélection ou dépôt d'un fichier, avec barre de progression. Le fichier est d'abord téléversé dans un espace
    temporaire privé ; le serveur contrôle ensuite son extension, son type réel et sa taille avant de le conserver.
--}}
<div x-data="{ survol: false, envoi: false, progression: 0 }"
    x-on:livewire-upload-start="envoi = true; progression = 0"
    x-on:livewire-upload-finish="envoi = false"
    x-on:livewire-upload-cancel="envoi = false"
    x-on:livewire-upload-error="envoi = false"
    x-on:livewire-upload-progress="progression = $event.detail.progress">
    <input type="file" id="{{ $id }}" x-ref="champ" class="sr-only" accept="{{ $accept }}" x-bind:disabled="envoi"
        {{ $attributes->whereStartsWith('wire:model') }} @disabled($attributes->get('disabled')) />

    @if ($compact)
        <label for="{{ $id }}" x-bind:class="envoi && 'pointer-events-none opacity-60'"
            class="inline-flex h-8 cursor-pointer items-center justify-center gap-2 rounded-md border border-bord bg-surface px-2.5 text-sm font-medium whitespace-nowrap text-encre transition-colors hover:border-marine-200 hover:bg-marine-50 focus-within:outline-2 focus-within:outline-bleu">
            <x-icone nom="upload" x-show="! envoi" />
            <x-rotation x-show="envoi" x-cloak />
            <span x-text="envoi ? `${progression} %` : @js($libelle)">{{ $libelle }}</span>
        </label>
    @else
        <label for="{{ $id }}"
            x-on:dragover.prevent="survol = true" x-on:dragleave="survol = false"
            x-on:drop.prevent="survol = false; if (! envoi) { $refs.champ.files = $event.dataTransfer.files; $refs.champ.dispatchEvent(new Event('change', { bubbles: true })) }"
            x-bind:class="{ 'border-marine! bg-marine-50!': survol, 'pointer-events-none opacity-60': envoi }"
            class="flex cursor-pointer flex-col items-center gap-1.5 rounded-md border border-dashed border-marine-200 bg-surface px-4 py-5 text-center transition-colors hover:border-marine hover:bg-marine-50/60 focus-within:outline-2 focus-within:outline-bleu">
            <x-icone nom="upload" class="size-5 text-marine" trait="1.75" />
            <span class="text-base font-medium text-encre" x-text="envoi ? 'Envoi en cours…' : @js($libelle)">{{ $libelle }}</span>
            <span class="text-sm text-discret">{{ $indication }} · ou glissez le fichier ici</span>
        </label>
    @endif

    <div class="mt-2" x-show="envoi" x-cloak role="progressbar" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="progression" aria-label="Progression de l'envoi">
        <div class="h-1.5 overflow-hidden rounded-full bg-marine-100">
            <div class="h-full rounded-full bg-marine transition-[width] duration-150" x-bind:style="`width: ${progression}%`"></div>
        </div>
    </div>

    @if ($fichier && ! $compact)
        <x-ligne-fichier class="mt-2" :nom="$fichier->getClientOriginalName()" :detail="\App\Support\Affichage\Format::taille($fichier->getSize())">
            <x-slot:actions>
                <x-bouton variante="discret" taille="sm" wire:click="$set('{{ $modele }}', null)">Retirer</x-bouton>
            </x-slot:actions>
        </x-ligne-fichier>
    @endif

    @error($modele)
        <p role="alert" class="mt-1.5 text-sm text-danger">{{ $message }}</p>
    @enderror
</div>
