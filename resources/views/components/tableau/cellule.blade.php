@props(['droite' => false, 'dense' => false])
<td {{ $attributes->class(['px-3 align-middle text-encre', $dense ? 'py-2' : 'py-2.5', 'chiffres text-right whitespace-nowrap' => $droite]) }}>{{ $slot }}</td>
