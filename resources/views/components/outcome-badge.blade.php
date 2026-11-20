@props(['outcome'])
@php $o = \App\Services\History\Outcome::tryFrom((string) $outcome); @endphp
<span {{ $attributes->merge(['class' => 'badge tone-'.($o?->tone() ?? 'neutral')]) }}>{{ $o?->label() ?? $outcome }}</span>
