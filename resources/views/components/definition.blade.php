@props(['terme'])
<div {{ $attributes->class('min-w-0') }}>
    <dt class="text-sm text-discret">{{ $terme }}</dt>
    <dd class="mt-0.5 text-base break-words text-encre">@if (trim($slot) === '')—@else{{ $slot }}@endif</dd>
</div>
