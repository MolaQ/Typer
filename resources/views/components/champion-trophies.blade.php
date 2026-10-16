@props(['team' => null, 'keys' => null, 'size' => 16, 'framed' => false])
{{--
    Miniatury trofeów aktualnych mistrzów i triumfatorów rozgrywek (App\Support\Champions, wersja C):
    mały puchar w barwach rozgrywek (CompetitionColors: gradient bg1 -> bg2, obrys w kolorze akcentu z ciemną
    obwódką bg1, żeby był widoczny na jasnym i ciemnym tle). Pełny tytuł w podpowiedzi.
    Parametry: team (SeasonTeam) albo keys (lista kluczy trofeów), size (px), framed (kafelek pod trofeum, np. na ciemnym banerze).
--}}
@php
    $trophyKeys = $keys ?? \App\Support\Champions::of($team);
    $trophyNames = $trophyKeys === [] ? [] : \App\Support\HallOfFame::trophies();
@endphp
@if ($trophyKeys !== [])
    <span {{ $attributes->class('inline-flex shrink-0 items-center gap-0.5 align-middle') }}>
        @foreach ($trophyKeys as $trophyKey)
            @php
                $c = \App\Support\CompetitionColors::for($trophyKey);
                $gradientId = 'trophy-' . $trophyKey . '-' . \Illuminate\Support\Str::random(6);
                $title = __('Current holder: :title', ['title' => $trophyNames[$trophyKey] ?? $trophyKey]);
            @endphp
            <span title="{{ $title }}" class="{{ $framed ? 'rounded-md bg-white/15 p-1 ring-1 ring-white/20' : '' }} inline-flex">
                <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" role="img" aria-label="{{ $title }}">
                    <defs>
                        <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="{{ $c['bg1'] }}" />
                            <stop offset="1" stop-color="{{ $c['bg2'] }}" />
                        </linearGradient>
                    </defs>
                    {{-- Ciemna obwódka pod spodem, na niej obrys w kolorze akcentu --}}
                    <g fill="none" stroke="{{ $c['bg1'] }}" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7 3h10v5a5 5 0 0 1-10 0z" />
                        <path d="M7 5H4v1.5a3 3 0 0 0 3 3M17 5h3v1.5a3 3 0 0 1-3 3" />
                        <path d="M12 13v4M8 20.5h8" />
                    </g>
                    <g stroke="{{ $c['accent'] }}" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M7 3h10v5a5 5 0 0 1-10 0z" fill="url(#{{ $gradientId }})" />
                        <path d="M7 5H4v1.5a3 3 0 0 0 3 3M17 5h3v1.5a3 3 0 0 1-3 3" fill="none" />
                        <path d="M12 13v4M8 20.5h8" fill="none" />
                    </g>
                </svg>
            </span>
        @endforeach
    </span>
@endif
