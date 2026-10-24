@props(['reductible' => false])
{{-- Marque de l'application. `reductible` : le texte disparaît quand la barre latérale est repliée. --}}
<div class="flex items-center gap-2.5">
    <span aria-hidden="true" class="flex size-8 shrink-0 items-center justify-center rounded-md bg-marine text-white">
        <svg viewBox="0 0 32 32" class="size-5" fill="currentColor"><path d="M9 22V12.2L16 8l7 4.2V22h-4.6v-5.4h-4.8V22z" /></svg>
    </span>
    <div class="min-w-0 leading-tight" @if ($reductible) x-show="! reduit" @endif>
        <p class="text-md font-semibold text-marine">Maisons des Stagiaires&nbsp;-&nbsp;OFPPT</p>
        <p class="text-2xs text-discret">Hébergement, restauration et vie des stagiaires</p>
    </div>
</div>
