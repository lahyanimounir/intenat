@props(['href' => null, 'selectionnee' => false])
{{-- Ligne de tableau. Avec `href`, un clic hors d'un lien ou d'un bouton ouvre la fiche. --}}
<tr @if ($href) x-data x-on:click="if (! $event.target.closest('a, button, input, select, label')) Livewire.navigate(@js($href))" @endif
    {{ $attributes->class(['border-b border-bord-doux transition-colors last:border-0 hover:bg-marine-50/60', 'cursor-pointer' => $href, 'bg-marine-50' => $selectionnee]) }}>
    {{ $slot }}
</tr>
