<?php

use App\Enums\Permission;
use App\Support\Audit;
use App\Support\Designation;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Ustawienia desygnowania zawodników (notatka desygnowanie.md): limit składu, składy botów, czyste konto,
 * blokady i punkty doświadczenia. Wartości domyślne w Support\Designation, zapis w designation_settings.
 * Podgląd z season-list, zmiany z season-edit.
 */
new class extends Component {
    /** klucz => wartość (tekst z formularza) */
    public array $values = [];

    public function mount(): void
    {
        $this->values = array_map('strval', Designation::values());
    }

    public function render(): View
    {
        return $this->view()->title(__('Line-ups'));
    }

    /** Pola w grupach: klucz => [etykieta, opis, min, max]. */
    public function fields(): array
    {
        return [
            __('Line-up') => [
                'max_players' => [__('Players per competition type'), __('A club fields at most this many players in one competition type per matchday.'), 1, 11],
                'bot_players_min' => [__('Bot: fewest players'), __('Bots field a random number of available players in each competition type.'), 0, 11],
                'bot_players_max' => [__('Bot: most players'), null, 0, 11],
            ],
            __('Clean sheet') => [
                'clean_sheet_goalkeeper' => [__('Goalkeeper'), __('Attack points taken from the rival for a clean sheet.'), 0, 10],
                'clean_sheet_defender' => [__('Defender'), null, 0, 10],
            ],
            __('Experience') => [
                'exp_full_half' => [__('Whole half'), __('Experience decides drawn cup ties before the tip time.'), 0, 10],
                'exp_partial_half' => [__('Part of a half'), null, 0, 10],
                'exp_start' => [__('Starting eleven'), null, 0, 10],
                'exp_win' => [__('Player match won'), __('Goals of Lech and the opponent while the player was on the pitch.'), 0, 10],
                'exp_draw' => [__('Player match drawn'), null, 0, 10],
                'exp_loss' => [__('Player match lost'), null, 0, 10],
            ],
        ];
    }

    /** Sposoby liczenia czystego konta. */
    public function cleanSheetModes(): array
    {
        return [
            Designation::CLEAN_MATCH => __('Lech concedes no goal in the whole match'),
            Designation::CLEAN_UNTIL_OFF => __('Lech concedes no goal until the player goes off'),
            Designation::CLEAN_FROM_ON => __('Lech concedes no goal after the player comes on'),
        ];
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->can(Permission::SeasonEdit->value), 403);

        $rules = ['values.clean_sheet_mode' => ['required', 'in:' . implode(',', array_keys($this->cleanSheetModes()))], 'values.partial_half_lock' => ['required', 'in:0,1']];
        $names = [];
        foreach ($this->fields() as $fields) {
            foreach ($fields as $key => [$label, , $min, $max]) {
                $rules['values.' . $key] = ['required', 'integer', 'min:' . $min, 'max:' . $max];
                $names['values.' . $key] = $label;
            }
        }
        $rules['values.bot_players_max'][] = 'gte:values.bot_players_min';
        $this->validate($rules, [], $names);

        $before = Designation::values();
        $now = now();

        DB::table('designation_settings')->upsert(
            collect(Designation::DEFAULTS)->keys()->map(fn($key) => [
                'key' => $key,
                'value' => (int) $this->values[$key],
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['key'],
            ['value', 'updated_at'],
        );

        Designation::forget();
        $after = Designation::values();

        $changed = array_keys(array_filter($after, fn($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        Audit::log('designation.settings', null, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)), __('Line-ups'));

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function restoreDefaults(): void
    {
        $this->values = array_map('strval', Designation::DEFAULTS);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Line-ups') }}</flux:heading>
        <flux:subheading>{{ __('Rules for fielding Lech players: line-up size, bots, clean sheets, locks and experience.') }}</flux:subheading>
    </div>

    @php
        $canEdit = auth()->user()->can(\App\Enums\Permission::SeasonEdit->value);
    @endphp

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach ($this->fields() as $group => $fields)
                <flux:card class="space-y-4">
                    <flux:heading size="lg">{{ $group }}</flux:heading>

                    @foreach ($fields as $key => [$label, $hint, $min, $max])
                        <div class="grid items-start gap-2 sm:grid-cols-[1fr_6rem]">
                            <div>
                                <flux:label>{{ $label }}</flux:label>
                                @if ($hint)
                                    <flux:text class="text-xs">{{ $hint }}</flux:text>
                                @endif
                            </div>
                            <flux:input type="number" wire:model="values.{{ $key }}" min="{{ $min }}" max="{{ $max }}" :disabled="! $canEdit" />
                        </div>
                        <flux:error name="values.{{ $key }}" />
                    @endforeach

                    @if ($group === __('Clean sheet'))
                        <flux:radio.group wire:model="values.clean_sheet_mode" :label="__('When does a clean sheet count?')" :disabled="! $canEdit">
                            @foreach ($this->cleanSheetModes() as $mode => $modeLabel)
                                <flux:radio :value="(string) $mode" :label="$modeLabel" />
                            @endforeach
                        </flux:radio.group>
                    @endif
                </flux:card>
            @endforeach

            <flux:card class="space-y-4">
                <flux:heading size="lg">{{ __('Locks') }}</flux:heading>
                <flux:text>{{ __('A fielded player who played a whole half rests for one matchday per half in this club (all competition types). A full match with extra time is four matchdays.') }}</flux:text>
                <flux:radio.group wire:model="values.partial_half_lock" :label="__('Part of a half')" :disabled="! $canEdit">
                    <flux:radio value="0" :label="__('No lock')" />
                    <flux:radio value="1" :label="__('One matchday, like a whole half')" />
                </flux:radio.group>
            </flux:card>
        </div>

        @if ($canEdit)
            <div class="flex flex-wrap gap-2">
                <flux:button type="submit" variant="primary" icon="check">{{ __('Save') }}</flux:button>
                <flux:button type="button" variant="ghost" icon="arrow-path" wire:click="restoreDefaults">{{ __('Restore defaults') }}</flux:button>
            </div>
        @endif
    </form>
</div>
