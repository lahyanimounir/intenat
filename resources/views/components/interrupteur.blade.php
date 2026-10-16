@props(['libelle'])
{{-- Interrupteur : une case à cocher native, présentée comme un commutateur. Le libellé est lu par les lecteurs d'écran. --}}
<label class="relative inline-flex cursor-pointer items-center">
    <input type="checkbox" role="switch" aria-label="{{ $libelle }}" {{ $attributes->class('peer sr-only') }} />
    <span aria-hidden="true"
        class="h-5 w-9 rounded-full bg-marine-200 transition-colors peer-checked:bg-marine peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-bleu peer-disabled:cursor-not-allowed peer-disabled:opacity-50
               after:absolute after:top-0.5 after:left-0.5 after:size-4 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:after:translate-x-4"></span>
</label>
