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
 * blokady i punkty doświadczenia (także połówki, np. 0,5). Wartości domyślne w Support\Designation, zapis w designation_settings.
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

    /** Pola w grupach: klucz => [etykieta, opis, min, max, krok]. */
    public function fields(): array
    {
        return [
            __('Line-up') => [
                'max_players' => [__('Players per competition type'), __('A club fields at most this many players in one competition type per matchday.'), 1, 11, 1],
                'bot_players_min' => [__('Bot: fewest players'), __('Bots field a random number of available players in each competition type.'), 0, 11, 1],
                'bot_players_max' => [__('Bot: most players'), null, 0, 11, 1],
            ],
            __('Clean sheet') => [
                'clean_sheet_goalkeeper' => [__('Goalkeeper'), __('Attack points taken from the rival when Lech concedes no goal and the player played.'), 0, 10, 1],
                'clean_sheet_defender' => [__('Defender'), null, 0, 10, 1],
            ],
            __('Experience') => [
                'exp_start' => [__('Starting eleven'), __('Experience decides drawn cup ties before the tip time.'), 0, 10, 0.5],
                'exp_sub' => [__('Substitute'), null, 0, 10, 0.5],
                'exp_full_half' => [__('Whole half'), __('Extra time counts as two more halves.'), 0, 10, 0.5],
                'exp_partial_half' => [__('Part of a half'), null, 0, 10, 0.5],
                'exp_clean_sheet' => [__('Clean sheet'), __('Goalkeeper and defender who played, Lech conceded no goal.'), 0, 10, 0.5],
                'exp_goal' => [__('Goal'), __('Also every goal in a penalty shootout.'), 0, 10, 0.5],
                'exp_assist' => [__('Assist'), null, 0, 10, 0.5],
            ],
        ];
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->can(Permission::SeasonEdit->value), 403);

        $rules = ['values.partial_half_lock' => ['required', 'in:0,1']];
        $names = [];
        foreach ($this->fields() as $fields) {
            foreach ($fields as $key => [$label, , $min, $max, $step]) {
                // Liczby całkowite albo z krokiem 0,5 (doświadczenie).
                $rules['values.' . $key] = ['required', 'numeric', 'min:' . $min, 'max:' . $max, $step === 1 ? 'integer' : 'multiple_of:0.5'];
                $names['values.' . $key] = $label;
            }
        }
        $rules['values.bot_players_max'][] = 'gte:values.bot_players_min';
        $this->values = array_map(fn ($v) => str_replace(',', '.', (string) $v), $this->values);
        $this->validate($rules, [], $names);

        $before = Designation::values();
        $now = now();

        DB::table('designation_settings')->upsert(
            collect(Designation::DEFAULTS)->keys()->map(fn($key) => [
                'key' => $key,
                'value' => (float) str_replace(',', '.', (string) $this->values[$key]),
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

                    @foreach ($fields as $key => [$label, $hint, $min, $max, $step])
                        <div class="grid items-start gap-2 sm:grid-cols-[1fr_6rem]">
                            <div>
                                <flux:label>{{ $label }}</flux:label>
                                @if ($hint)
                                    <flux:text class="text-xs">{{ $hint }}</flux:text>
                                @endif
                            </div>
                            <flux:input type="number" wire:model="values.{{ $key }}" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" :disabled="! $canEdit" />
                        </div>
                        <flux:error name="values.{{ $key }}" />
                    @endforeach

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
