<?php

use App\Models\TeamNameChangeRequest;
use App\Support\Audit;
use App\Support\TeamRules;
use Flux\Flux;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $teamName = '';
    public string $teamShortName = '';
    public string $teamAbbr = '';

    private const FIELDS = [
        'teamName' => 'team_name',
        'teamShortName' => 'team_short_name',
        'teamAbbr' => 'team_abbr',
    ];

    public function mount(): void
    {
        $this->fillFromUser();
    }

    /** Konto bez żadnej roli zmienia dane od razu, pozostałe wysyłają prośbę. */
    #[Computed]
    public function canRenameDirectly(): bool
    {
        return !auth()->user()->roles()->exists();
    }

    #[Computed]
    public function pendingRequest(): ?TeamNameChangeRequest
    {
        return TeamNameChangeRequest::query()
            ->where('user_id', auth()->id())
            ->pending()
            ->latest()
            ->first();
    }

    protected function rules(): array
    {
        $id = auth()->id();

        return [
            'teamName' => TeamRules::name($id),
            'teamShortName' => TeamRules::shortName($id),
            'teamAbbr' => TeamRules::abbr($id),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'teamName' => __('Team name'),
            'teamShortName' => __('Team short name'),
            'teamAbbr' => __('Team abbreviation'),
        ];
    }

    /* ------------------------------------------------------------------
     | Walidacja na żywo
     * ----------------------------------------------------------------*/

    public function updated(string $property, mixed $value = null): void
    {
        if (!array_key_exists($property, self::FIELDS)) {
            return;
        }

        // Lekka normalizacja w trakcie pisania. Pełna jest przy zapisie.
        $this->{$property} = $property === 'teamAbbr'
            ? mb_strtoupper(trim((string) $this->{$property}))
            : preg_replace('/\s{2,}/u', ' ', ltrim((string) $this->{$property}));

        $this->validateOnly($property);
    }

    /** Czy pole różni się od aktualnej wartości zapisanej w koncie. */
    public function changed(string $property): bool
    {
        return trim($this->{$property}) !== (string) auth()->user()->{self::FIELDS[$property]};
    }

    private function hasChanges(): bool
    {
        foreach (array_keys(self::FIELDS) as $property) {
            if ($this->changed($property)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------
     | Zapis
     * ----------------------------------------------------------------*/

    public function save(): void
    {
        $this->normalizeFields();
        $this->validate();

        if (!$this->hasChanges()) {
            $this->addError('form', __('Change at least one of the values.'));

            return;
        }

        $user = auth()->user();
        $before = $this->currentValues();
        $after = $this->formValues();

        if ($this->canRenameDirectly) {
            try {
                $user->update($after);
            } catch (UniqueConstraintViolationException) {
                $this->addError('form', __('One of these values has just been taken. Try different ones.'));

                return;
            }

            Audit::log('team_name.changed', $user, $before, $after, __('Changed by the user.'));
            Flux::toast(text: __('Team details saved.'), variant: 'success');

            return;
        }

        if ($this->pendingRequest) {
            Flux::toast(text: __('You already have a request awaiting approval.'), variant: 'warning');

            return;
        }

        $request = TeamNameChangeRequest::create([
            'user_id' => $user->id,
            'current_team_name' => $before['team_name'],
            'current_team_short_name' => $before['team_short_name'],
            'current_team_abbr' => $before['team_abbr'],
            'requested_team_name' => $after['team_name'],
            'requested_team_short_name' => $after['team_short_name'],
            'requested_team_abbr' => $after['team_abbr'],
        ]);

        Audit::log('team_name.requested', $user, $before, $after, __('Request #:id', ['id' => $request->id]));

        unset($this->pendingRequest);
        $this->fillFromUser();

        Flux::toast(text: __('Request sent. An administrator will review it.'), variant: 'success');
    }

    public function cancelRequest(): void
    {
        $request = $this->pendingRequest;

        if (!$request) {
            return;
        }

        Audit::log(
            'team_name.cancelled',
            auth()->user(),
            [
                'team_name' => $request->requested_team_name,
                'team_short_name' => $request->requested_team_short_name,
                'team_abbr' => $request->requested_team_abbr,
            ],
            [],
            __('Request #:id cancelled by the user.', ['id' => $request->id]),
        );

        $request->delete();

        unset($this->pendingRequest);
        Flux::toast(text: __('Request cancelled.'));
    }

    /* ------------------------------------------------------------------
     | Pomocnicze
     * ----------------------------------------------------------------*/

    private function currentValues(): array
    {
        $user = auth()->user();

        return [
            'team_name' => $user->team_name,
            'team_short_name' => $user->team_short_name,
            'team_abbr' => $user->team_abbr,
        ];
    }

    private function formValues(): array
    {
        return [
            'team_name' => $this->teamName,
            'team_short_name' => $this->teamShortName,
            'team_abbr' => $this->teamAbbr,
        ];
    }

    private function normalizeFields(): void
    {
        $normalized = TeamRules::normalize($this->formValues());

        $this->teamName = $normalized['team_name'];
        $this->teamShortName = $normalized['team_short_name'];
        $this->teamAbbr = $normalized['team_abbr'];
    }

    private function fillFromUser(): void
    {
        $values = $this->currentValues();

        $this->teamName = (string) $values['team_name'];
        $this->teamShortName = (string) $values['team_short_name'];
        $this->teamAbbr = (string) $values['team_abbr'];
        $this->resetValidation();
    }
};
?>

<section class="w-full space-y-6">
    <div>
        <flux:heading size="lg">{{ __('Team') }}</flux:heading>
        <flux:text class="mt-1">
            @if ($this->canRenameDirectly)
                {{ __('You can change your team details at any time.') }}
            @else
                {{ __('Changes to your team details must be approved by an administrator.') }}
            @endif
        </flux:text>
    </div>

    {{-- Oczekująca prośba --}}
    @if ($this->pendingRequest)
    @php($pending = $this->pendingRequest)

    <flux:card class="space-y-3">
        <div class="flex items-center justify-between gap-4">
            <flux:heading>{{ __('Request awaiting approval') }}</flux:heading>
            <flux:badge color="amber" size="sm">{{ __('Pending') }}</flux:badge>
        </div>

        <dl class="grid gap-2 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-zinc-500">{{ __('Team name') }}</dt>
                <dd class="font-medium">{{ $pending->requested_team_name }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('Team short name') }}</dt>
                <dd class="font-medium">{{ $pending->requested_team_short_name }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500">{{ __('Team abbreviation') }}</dt>
                <dd class="font-medium">{{ $pending->requested_team_abbr }}</dd>
            </div>
        </dl>

        <flux:text class="text-sm">{{ __('Sent') }}: {{ $pending->created_at->format('Y-m-d H:i') }}</flux:text>

        <div>
            <flux:button size="sm" variant="ghost" wire:click="cancelRequest"
                wire:confirm="{{ __('Cancel this request?') }}">
                {{ __('Cancel request') }}
            </flux:button>
        </div>
    </flux:card>
    @endif

    {{-- Formularz --}}
    <form wire:submit="save" class="space-y-6">
        <div class="space-y-1">
            <flux:input wire:model.live.blur="teamName" :label="__('Team name')" type="text" maxlength="40"
                :disabled="$this->pendingRequest !== null" />
            @if ($this->changed('teamName') && !$errors->has('teamName'))
                <p class="text-sm text-green-600 dark:text-green-400">{{ __('Available') }}</p>
            @endif
        </div>

        <div class="space-y-1">
            <flux:input wire:model.live.blur="teamShortName" :label="__('Team short name')" type="text" maxlength="20"
                :disabled="$this->pendingRequest !== null" />
            @if ($this->changed('teamShortName') && !$errors->has('teamShortName'))
                <p class="text-sm text-green-600 dark:text-green-400">{{ __('Available') }}</p>
            @endif
        </div>

        <div class="space-y-1">
            <flux:input wire:model.live.blur="teamAbbr" :label="__('Team abbreviation')" type="text" maxlength="6"
                class="uppercase" :disabled="$this->pendingRequest !== null" />
            @if ($this->changed('teamAbbr') && !$errors->has('teamAbbr'))
                <p class="text-sm text-green-600 dark:text-green-400">{{ __('Available') }}</p>
            @endif
        </div>

        <flux:error name="form" />

        <flux:button variant="primary" type="submit" :disabled="$this->pendingRequest !== null">
            <span wire:loading.remove wire:target="save">
                {{ $this->canRenameDirectly ? __('Save changes') : __('Send change request') }}
            </span>
            <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
        </flux:button>
    </form>
</section>