{{--
    Protokół meczu rozliczonej kolejki (Support\MatchSheet) w karcie typu rozgrywek na /tips: wynik, a pod nim „strzelcy” obu stron.
    Przy nazwie zespołu w wyniku typ gracza (szary, w nawiasie). Strzelcy to tylko wystawieni zawodnicy: piłki za punkty ataku, czerwona piłka
    za samobója, pięść za czyste konto; w kolumnach kartki i doświadczenie w klubie po meczu. Szarzy = nie zagrali w meczu Lecha.
    Zmienne: $sheet, $showCompetition.
--}}
<div class="overflow-hidden rounded-xl bg-white text-zinc-800 shadow-sm dark:bg-zinc-900 dark:text-zinc-100" wire:key="sheet-{{ $sheet['fixture_id'] }}">
    @if ($showCompetition)
        <div class="truncate bg-zinc-50 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-zinc-500 dark:bg-white/5">{{ $sheet['competition'] }}</div>
    @endif

    {{-- Wynik: zespół i bramki, zwycięzca pogrubiony --}}
    <div class="space-y-0.5 px-2 py-1.5">
        @foreach ($sheet['sides'] as $side)
            <div class="flex items-center gap-2 text-sm {{ $side['winner'] ? 'font-black' : 'font-semibold' }} {{ $side['mine'] ? 'text-lech-800 dark:text-lech-200' : '' }}">
                <span class="flex min-w-0 flex-1 items-baseline gap-1">
                    <span class="min-w-0 truncate {{ $side['bot'] ? 'italic' : '' }}">{{ $side['name'] }}</span>
                    @if (!$side['virtual'])
                        <span class="shrink-0 text-[10px] font-normal tabular-nums text-zinc-400" title="{{ __('Tip') }}">({{ $side['tip'] ?? '–' }})</span>
                    @endif
                </span>
                <span class="shrink-0 rounded bg-zinc-100 px-1.5 tabular-nums dark:bg-white/10">{{ $side['goals'] }}</span>
            </div>
        @endforeach
        @if ($sheet['by_time'])
            <div class="text-right text-[10px] text-zinc-500">{{ __('after penalties') }}</div>
        @endif
    </div>

    {{-- Strzelcy obu stron --}}
    @foreach ($sheet['sides'] as $side)
        <div class="border-t border-zinc-100 px-2 py-1.5 dark:border-white/10">
            @if (!$side['virtual'])
                <div class="mb-0.5 flex items-center gap-1 text-[9px] font-semibold uppercase text-zinc-400">
                    <span class="flex-1"></span>
                    <span class="flex w-8 shrink-0 justify-center" title="{{ __('Cards') }}"><span class="inline-block h-2.5 w-2 rounded-[2px] bg-zinc-300 dark:bg-zinc-600"></span></span>
                    <span class="w-8 shrink-0 text-right" title="{{ __('Experience in the club after the match') }}">{{ __('Exp.') }}</span>
                </div>
            @endif

            @if ($side['virtual'])
                <div class="text-[11px] text-zinc-500">{{ __('Goals of Lech in the real match') }}</div>
            @elseif ($side['rows'] === [])
                <div class="text-[11px] text-zinc-400">{{ __('Nobody was fielded.') }}</div>
            @else
                <ul class="space-y-0.5 text-[11px]">
                    @foreach ($side['rows'] as $row)
                        <li class="{{ $row['played'] ? '' : 'text-zinc-400 dark:text-zinc-500' }} flex items-center gap-1">
                            <span class="w-3 shrink-0 text-[9px] font-semibold">{{ $row['position']->short() }}</span>
                            <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1 gap-y-0.5">
                                <span class="min-w-0 max-w-full truncate {{ $row['counted'] ? '' : 'line-through' }}" title="{{ $row['name'] }}">{{ $row['short'] }}</span>
                                @for ($i = 0; $i < $row['attack']; $i++)
                                    <x-ball />
                                @endfor
                                @for ($i = 0; $i < $row['own_goals']; $i++)
                                    <x-ball own />
                                @endfor
                                @for ($i = 0; $i < $row['defense']; $i++)
                                    <x-fist />
                                @endfor
                            </span>
                            <span class="flex w-8 shrink-0 items-center justify-center gap-0.5">
                                @for ($i = 0; $i < $row['yellow']; $i++)
                                    <span class="inline-block h-3 w-2 rounded-[2px] bg-yellow-400"></span>
                                @endfor
                                @if ($row['red'])
                                    <span class="inline-block h-3 w-2 rounded-[2px] bg-red-500"></span>
                                @endif
                            </span>
                            <span class="w-8 shrink-0 text-right font-semibold tabular-nums">{{ \App\Support\ClubStats::experience($row['experience']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</div>
