{{--
    Legenda zegara czasu gry (x-match-clock): ćwiartki osi meczu i dogrywka w drugim kolorze.
--}}
<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] text-zinc-500">
    @foreach ([[-1, 4, __('did not play')], [0, 1, __('1st half')], [0, 2, __('half-time')], [0, 3, __('2nd half')], [0, 4, __('until the end (incl. stoppage time)')], [0, 6, __('extra time')]] as [$from, $to, $label])
        <span class="inline-flex items-center gap-1"><x-match-clock :from="$from" :to="$to" />{{ $label }}</span>
    @endforeach
</div>
