{{--
    Baner strony publicznej (wzór ze strony głównej): gradient sidebara, nadtytuł, duży tytuł i podtytuł.
    Slot domyślny trafia pod podtytuł, slot „aside” po prawej stronie (np. sponsor, liczby).
    Użycie: <x-page-banner :title="__('Hall of Fame')" :subtitle="..." />
--}}
@props(['title', 'subtitle' => null, 'eyebrow' => 'LechTYPER'])

<section {{ $attributes->class('lech-banner relative overflow-hidden rounded-2xl px-6 py-10 sm:px-10') }}>
    <div class="relative flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
        <div class="min-w-0 space-y-3">
            @if (filled($eyebrow))
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-lech-200">{{ $eyebrow }}</p>
            @endif
            <h1 class="text-4xl font-black tracking-tight sm:text-5xl">{{ $title }}</h1>
            @if (filled($subtitle))
                <p class="max-w-2xl text-lg text-lech-100 sm:text-xl">{{ $subtitle }}</p>
            @endif
            {{ $slot }}
        </div>

        @if (isset($aside) && $aside->isNotEmpty())
            <div class="shrink-0">{{ $aside }}</div>
        @endif
    </div>
</section>
