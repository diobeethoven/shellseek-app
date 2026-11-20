{{-- Outcome message of the last POST /recon (Post/Redirect/Get flash). --}}
<div class="alert tone-{{ $flash['tone'] }}" role="status" id="result" data-status="{{ $flash['status'] }}">
    <span class="alert-icon">{{ $flash['tone'] === 'ok' ? '✓' : '!' }}</span>
    <div><strong id="message">{{ $flash['message'] }}</strong></div>
</div>
