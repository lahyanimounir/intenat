@props(['libelle' => null, 'pour' => null, 'erreur' => null, 'aide' => null, 'requis' => false])
{{-- Champ de formulaire : libellé, aide et message d'erreur reliés au contrôle. Sans libellé, seul le contrôle est rendu. --}}
@if ($libelle === null)
    {{ $slot }}
    @if ($erreur)<p id="{{ $pour }}-erreur" role="alert" class="mt-1.5 text-sm text-danger">{{ $erreur }}</p>@endif
@else
    <div {{ $attributes->class('flex flex-col gap-1.5') }}>
        <label for="{{ $pour }}" class="text-sm font-medium text-encre">
            {{ $libelle }}@if ($requis)<span aria-hidden="true" class="ml-0.5 text-danger">*</span>@endif
        </label>
        {{ $slot }}
        @if ($erreur)
            <p id="{{ $pour }}-erreur" role="alert" class="text-sm text-danger">{{ $erreur }}</p>
        @elseif ($aide)
            <p id="{{ $pour }}-aide" class="text-sm text-discret">{{ $aide }}</p>
        @endif
    </div>
@endif
