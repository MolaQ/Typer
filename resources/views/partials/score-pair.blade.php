{{--
    Mały wynik z przyciskami + / − (raport meczu: gole w dogrywce, seria karnych). Klik woła bump($key, ±1) komponentu.
    Zmienne: $label, $lechKey, $oppKey, $lechValue, $oppValue, $editable, $miniBtn.
--}}
<div class="flex items-center justify-between gap-2 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5">
    <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ $label }}</span>
    <div class="flex items-center gap-1">
        @foreach ([[$lechKey, $lechValue], [$oppKey, $oppValue]] as [$pairKey, $pairValue])
            @if (!$loop->first)
                <span class="px-0.5 font-black text-zinc-400">:</span>
            @endif
            <button type="button" wire:click="bump('{{ $pairKey }}', -1)" @disabled(!$editable || !$pairValue) class="{{ $miniBtn }}" aria-label="−">−</button>
            <span class="w-5 text-center text-base font-black tabular-nums">{{ $pairValue }}</span>
            <button type="button" wire:click="bump('{{ $pairKey }}', 1)" @disabled(!$editable) class="{{ $miniBtn }}" aria-label="+">+</button>
        @endforeach
    </div>
</div>
