{{-- Znak LechTYPER: tarcza w barwach Lecha z ptakiem o skrzydle z piór (nawiązanie do herbu i dawnego
     znaczka Twittera, gdzie zaczęła się zabawa). Projekt roboczy: plik można podmienić na docelowe logo. --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" {{ $attributes }}>
    <defs>
        <linearGradient id="lechtyper-shield" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#1d4ed8" />
            <stop offset="1" stop-color="#0b1f4d" />
        </linearGradient>
    </defs>
    <path d="M32 2 L58 10 V32 C58 46 47 56 32 62 C17 56 6 46 6 32 V10 Z" fill="url(#lechtyper-shield)" />
    <path d="M32 6 L54 13 V32 C54 44 45 52.5 32 57.5 C19 52.5 10 44 10 32 V13 Z" fill="none" stroke="#fff"
        stroke-opacity=".35" stroke-width="1.2" />
    <path fill="#fff"
        d="M17 38 C21 46 33 48 40 42 C45 38 46 31 45 27 L50 24.5 L45.5 23.5 C44 20 40.5 18.5 37 19.5 C33.5 20.5 32 24 32.5 27.5 C27 28 21 33 17 38 Z" />
    <circle cx="40" cy="23.5" r="1.2" fill="#0b1f4d" />
    <path fill="#fff" d="M30 31 C26 25 25 18 27 12 C29 17 31 21 34 24 Z" />
    <path fill="#fff" d="M27 33 C21 29 18 23 18 16 C21 21 25 25 30 28 Z" />
    <path fill="#fff" d="M24 35 C17 33 13 28 11 22 C15 26 20 29 26 31 Z" />
</svg>
