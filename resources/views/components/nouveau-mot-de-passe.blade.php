@props(['modele' => 'mot_de_passe', 'libelle' => 'Mot de passe'])
{{-- Choix d'un mot de passe INTERNAT : saisie, exigences cochées au fil de la frappe (la même politique que le serveur applique), confirmation. --}}
<div class="space-y-4" x-data="{ valeur: '' }">
    <x-mot-de-passe :libelle="$libelle" wire:model="{{ $modele }}" x-model="valeur" autocomplete="new-password" requis />

    <ul aria-label="Exigences du mot de passe" class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
        @foreach ([
            ['Dix caractères au moins', 'valeur.length >= 10'],
            ['Une lettre minuscule', '/\p{Ll}/u.test(valeur)'],
            ['Une lettre majuscule', '/\p{Lu}/u.test(valeur)'],
            ['Un chiffre', '/\d/.test(valeur)'],
        ] as [$exigence, $regle])
            <li class="flex items-center gap-2 text-discret" x-bind:class="{{ $regle }} ? 'text-succes!' : ''">
                <x-icone nom="check" class="size-3.5" trait="2.5" x-show="{{ $regle }}" x-cloak />
                <x-icone nom="minus" class="size-3.5" x-show="! ({{ $regle }})" />
                <span>{{ $exigence }}<span class="sr-only" x-text="{{ $regle }} ? ' : respectée' : ' : à respecter'"></span></span>
            </li>
        @endforeach
    </ul>

    <x-mot-de-passe libelle="Confirmer le mot de passe" wire:model="{{ $modele }}_confirmation" autocomplete="new-password" requis />
</div>
