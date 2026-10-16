@props(['etapes', 'courante' => 0, 'action' => null])
{{-- Indicateur de progression d'un parcours. `etapes` : liste de libellés ; `action` : méthode Livewire recevant l'indice d'une étape déjà franchie. --}}
<ol class="flex flex-wrap gap-x-2 gap-y-3" aria-label="Étapes">
    @foreach ($etapes as $indice => $libelle)
        @php
            $faite = $indice < $courante;
            $active = $indice === $courante;
            $cliquable = $action !== null && $indice <= $courante;
        @endphp
        <li class="flex min-w-0 flex-1 basis-36 items-center gap-2">
            <button type="button" @disabled(! $cliquable) @if ($active) aria-current="step" @endif
                @if ($cliquable) wire:click="{{ $action }}({{ $indice }})" @endif
                @class(['flex min-w-0 flex-1 items-center gap-2.5 rounded-md py-1 text-left disabled:cursor-default', 'hover:bg-marine-50' => $cliquable])>
                <span @class([
                    'chiffres flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold',
                    'border-marine bg-marine text-white' => $faite,
                    'border-marine bg-surface text-marine' => $active,
                    'border-marine-200 bg-surface text-discret' => ! $faite && ! $active,
                ])>
                    @if ($faite)<x-icone nom="check" class="size-3.5" trait="3" />@else{{ $indice + 1 }}@endif
                </span>
                <span @class(['truncate text-sm', 'font-medium text-encre' => $active, 'text-encre-2' => $faite, 'text-discret' => ! $faite && ! $active])>{{ $libelle }}</span>
            </button>
            @if (! $loop->last)<span aria-hidden="true" @class(['hidden h-px w-6 shrink-0 sm:block', 'bg-marine' => $faite, 'bg-marine-200' => ! $faite])></span>@endif
        </li>
    @endforeach
</ol>
