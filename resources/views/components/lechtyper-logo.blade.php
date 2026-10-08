{{-- Logo serwisu: znak (x-app-logo-icon) i napis LechTYPER. --}}
@props(['href' => route('home'), 'size' => 'md'])

@php
    $icon = $size === 'lg' ? 'size-12' : 'size-9';
    $text = $size === 'lg' ? 'text-2xl' : 'text-lg';
@endphp

<a href="{{ $href }}" wire:navigate {{ $attributes->class('flex items-center gap-2') }}>
    <x-app-logo-icon class="{{ $icon }} shrink-0 drop-shadow" />
    <span class="{{ $text }} font-black tracking-tight">
        <span>Lech</span><span class="font-light text-blue-500 dark:text-blue-300">TYPER</span>
    </span>
</a>
