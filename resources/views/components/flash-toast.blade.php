@php
    $flash = collect(['success' => 'success', 'warning' => 'warning', 'error' => 'danger'])
        ->map(fn($variant, $key) => session($key) ? ['text' => session($key), 'variant' => $variant] : null)
        ->filter()
        ->first();
@endphp

@if ($flash)
    <div x-data x-init="$nextTick(() => $flux.toast(@js($flash)))" class="hidden"></div>
@endif