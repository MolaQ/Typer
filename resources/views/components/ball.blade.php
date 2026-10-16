{{--
    Piłka w protokole meczu: czarna = punkt ataku (gol, asysta, punkt za typ), czerwona = samobój (punkt dla rywala).
    Rysunek: biała piłka z pięciokątem w środku i łatkami przy brzegu. Props: own (czerwona), class.
--}}
@props(['own' => false])
<svg viewBox="0 0 24 24" aria-hidden="true" {{ $attributes->class(['inline-block size-3.5 shrink-0', $own ? 'text-red-600' : 'text-zinc-900 dark:text-zinc-100']) }}>
    <defs><clipPath id="lt-ball-clip"><circle cx="12" cy="12" r="10"/></clipPath></defs>
    <circle cx="12" cy="12" r="10" class="fill-white dark:fill-zinc-900"/>
    <g clip-path="url(#lt-ball-clip)" fill="currentColor">
        <polygon points="12.00,8.60 15.23,10.95 14.00,14.75 10.00,14.75 8.77,10.95"/>
        <polygon points="16.58,5.69 15.46,2.22 18.41,0.08 21.36,2.22 20.23,5.69"/><polygon points="19.42,14.41 22.37,12.27 25.31,14.41 24.19,17.88 20.54,17.88"/><polygon points="12.00,19.80 14.95,21.94 13.82,25.41 10.18,25.41 9.05,21.94"/><polygon points="4.58,14.41 3.46,17.88 -0.19,17.88 -1.31,14.41 1.63,12.27"/><polygon points="7.42,5.69 3.77,5.69 2.64,2.22 5.59,0.08 8.54,2.22"/>
        <path d="M12.0 8.6L12.0 5.2 M15.2 10.9L18.5 9.9 M14.0 14.8L16.0 17.5 M10.0 14.8L8.0 17.5 M8.8 10.9L5.5 9.9" stroke="currentColor" stroke-width="1.1" fill="none"/>
    </g>
    <circle cx="12" cy="12" r="10.25" fill="none" stroke="currentColor" stroke-width="1.5"/>
</svg>
