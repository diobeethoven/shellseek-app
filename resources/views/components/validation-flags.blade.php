@props(['flags' => null])
@if (is_array($flags) && $flags !== [])
    <ul class="flags" id="validation-flags">
        @foreach ($flags as $flag => $ok)
            <li><span class="{{ $ok ? 'yes' : 'no' }}">{{ $ok ? '✓' : '✗' }}</span> {{ $flag }}: {{ $ok ? 'true' : 'false' }}</li>
        @endforeach
    </ul>
@else
    <p class="muted">No validation details (no decision was received from the Scope Enforcer).</p>
@endif
