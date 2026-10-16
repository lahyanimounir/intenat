@props(['libelle', 'options' => [], 'tous' => 'tous', 'actif' => false])
{{-- Filtre de liste. `options` : tableau valeur => libellé, ou liste de ['valeur' => …, 'libelle' => …]. --}}
<select aria-label="{{ $libelle }}"
    {{ $attributes->class([
        'h-8 max-w-56 rounded-md border bg-surface pr-7 pl-2.5 text-sm shadow-xs hover:border-marine-200',
        'border-marine-200 font-medium text-marine' => $actif,
        'border-bord text-encre-2' => ! $actif,
    ]) }}>
    <option value="">{{ $libelle }} : {{ $tous }}</option>
    @foreach ($options as $valeur => $option)
        @if (is_array($option))
            <option value="{{ $option['valeur'] }}">{{ $option['libelle'] }}</option>
        @else
            <option value="{{ $valeur }}">{{ $option }}</option>
        @endif
    @endforeach
</select>
