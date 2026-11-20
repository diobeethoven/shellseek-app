@props(['decision' => null, 'origin' => null])
@php
    $failClosed = $origin === 'fail_closed';
    $class = match ($decision) {
        'ALLOW' => 'badge-allow',
        'WARNING' => 'badge-warning',
        'DENY' => 'badge-deny',
        default => 'badge-neutral',
    };
@endphp
<span {{ $attributes->merge(['class' => 'badge '.$class]) }} data-decision="{{ $decision ?? 'none' }}">
    {{ $decision ?? 'NO DECISION' }}@if ($failClosed)<span class="badge-sub">· treated as DENY</span>@endif
</span>
