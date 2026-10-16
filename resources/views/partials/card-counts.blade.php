{{-- Kartki jako małe prostokąty z liczbą (żółte, czerwone). Zmienne: $yellow, $red. --}}
@if ($yellow)
    <span class="inline-flex items-center gap-0.5 whitespace-nowrap"><span class="inline-block h-3 w-2 rounded-[2px] bg-yellow-400"></span>{{ $yellow }}</span>
@endif
@if ($red)
    <span class="inline-flex items-center gap-0.5 whitespace-nowrap"><span class="inline-block h-3 w-2 rounded-[2px] bg-red-500"></span>{{ $red }}</span>
@endif
