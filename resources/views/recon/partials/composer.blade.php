{{-- Natural-language input. Plain POST (works without JS); app.js adds Enter-to-send and the pending state.
     $home = true renders the centred start-screen variant (example in the placeholder, policy note in the page footer). --}}
@php $home = $home ?? false; @endphp
<div @class(['composer-wrap', 'composer-home' => $home])>
    <form class="composer" method="POST" action="{{ route('recon.store') }}" data-recon-form>
        @csrf
        <label for="instruction" class="sr-only">Reconnaissance instruction</label>
        <div @class(['composer-box', 'has-error' => $errors->has('instruction')])>
            <textarea id="instruction" name="instruction" rows="1" maxlength="1000" required @if ($autofocus ?? false) autofocus @endif
                      placeholder="{{ $home ? 'Describe a recon task... "Scan services on <approved lab target IP> ports 80 and 443"' : 'Describe your reconnaissance task...' }}" data-composer-input>{{ old('instruction') }}</textarea>
            <div class="composer-bar">
                <span class="composer-mode" title="Footprinting &amp; scanning only">Recon</span>
                <span class="composer-model" title="Local model – proposes the command only">{{ config('llm.model') }}</span>
                <span class="composer-chip hide-sm" title="Every command is checked by the Scope Enforcer before anything runs">Ollama · local</span>
                <span class="spacer"></span>
                <span class="composer-count" data-count aria-live="polite"></span>
                <button class="composer-send" type="submit" aria-label="Generate &amp; validate">
                    <svg class="send-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="spinner" aria-hidden="true"></span>
                </button>
            </div>
        </div>
        @error('instruction')<p class="field-error">{{ $message }}</p>@enderror
        <p class="composer-hint">
            @unless ($home)
                <span class="composer-note">Footprinting &amp; scanning only, approved lab targets. The LLM only proposes; the Scope Enforcer decides.</span>
            @endunless
            <span class="composer-keys hide-sm"><b>enter</b> send <b>shift+enter</b> new line</span>
        </p>
    </form>
</div>
