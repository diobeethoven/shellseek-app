@props(['heading', 'meta' => null])
{{-- Slim header for the chat views; the slot holds header actions. --}}
<header class="chat-head">
    <button class="menu-toggle" type="button" data-menu-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">☰</button>
    <div class="chat-head-text">
        <h1>{{ $heading }}</h1>
        @if ($meta)<div class="chat-head-meta">{{ $meta }}</div>@endif
    </div>
    <div class="spacer"></div>
    {{ $slot }}
</header>
