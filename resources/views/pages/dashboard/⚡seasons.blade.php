<?php

use App\Enums\Permission;
use App\Actions\Seasons\FinishSeason;
use App\Actions\Seasons\ApproveSeason;
use App\Actions\Seasons\RevertApproval;
use App\Enums\MatchdayStatus;
use App\Enums\SeasonStatus;
use App\Models\Matchday;
use App\Models\Season;
use App\Support\Audit;
use App\Support\Roman;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination, WithFileUploads;

    // --- lista: wyszukiwanie, filtr, sortowanie (zapamiętane w adresie) ---
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 'number')]
    public string $sortBy = 'number';

    #[Url(except: 'desc')]
    public string $sortDirection = 'desc';

    // --- formularz sezonu ---
    public ?int $seasonId = null;
    public string $number = '';
    public string $slogan = '';
    public string $sponsorName = '';
    public string $sponsorUrl = '';
    public $logo = null; // nowo wgrany plik (tymczasowy)
    public ?string $currentLogoPath = null; // logo zapisane w bazie
    public bool $removeLogo = false;

    // --- usuwanie ---
    public ?int $deleteId = null;
    public string $deleteTitle = '';

    // Potwierdzenie aktywacji / zakończenia sezonu (modal Flux zamiast okna przeglądarki)
    public ?int $statusId = null;
    public string $statusAction = ''; // 'approve', 'unapprove', 'activate' albo 'finish'
    public string $statusTitle = '';
    public string $statusMessage = '';

    public function render(): View
    {
        return $this->view()->title(__('Season setup'));
    }

    /* ==================================================================
     | DANE DO WIDOKU
     * ================================================================*/

    #[Computed]
    public function stats(): array
    {
        $counts = Season::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'all' => (int) $counts->sum(),
            'finished' => (int) ($counts[SeasonStatus::Finished->value] ?? 0),
        ];
    }

    #[Computed]
    public function activeSeason(): ?Season
    {
        return Season::current();
    }

    #[Computed]
    public function seasons()
    {
        $statuses = array_column(SeasonStatus::cases(), 'value');

        return Season::query()
            ->when(in_array($this->status, $statuses, true), fn($q) => $q->where('status', $this->status))
            ->when($this->search !== '', function ($q) {
                $term = '%' . $this->search . '%';

                $q->where(fn($q) => $q->where('slogan', 'like', $term)->orWhere('sponsor_name', 'like', $term)->orWhere('number', 'like', $term));
            })
            ->orderBy($this->safeSortBy(), $this->safeDirection())
            ->paginate(10, pageName: 'seasonsPage');
    }

    /** Podgląd zapisu rzymskiego obok pola numeru (null, gdy numer niepoprawny). */
    #[Computed]
    public function romanPreview(): ?string
    {
        $n = trim($this->number);

        if (!ctype_digit($n)) {
            return null;
        }

        $n = (int) $n;

        return $n >= Roman::MIN && $n <= Roman::MAX ? Roman::toRoman($n) : null;
    }

    /** Adres obrazka do podglądu w formularzu: świeżo wgrany plik albo logo z bazy. */
    #[Computed]
    public function logoPreview(): ?string
    {
        if ($this->logo && $this->logo->isPreviewable()) {
            return $this->logo->temporaryUrl();
        }

        if ($this->currentLogoPath && !$this->removeLogo) {
            return asset('storage/' . $this->currentLogoPath);
        }

        return null;
    }

    /* ==================================================================
     | SORTOWANIE I FILTRY
     * ================================================================*/

    private function sortableColumns(): array
    {
        return ['number', 'created_at'];
    }

    private function safeSortBy(): string
    {
        return in_array($this->sortBy, $this->sortableColumns(), true) ? $this->sortBy : 'number';
    }

    private function safeDirection(): string
    {
        return $this->sortDirection === 'asc' ? 'asc' : 'desc';
    }

    public function sort(string $column): void
    {
        if (!in_array($column, $this->sortableColumns(), true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage('seasonsPage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('seasonsPage');
    }

    public function updatedStatus(): void
    {
        $this->resetPage('seasonsPage');
    }

    /* ==================================================================
     | WALIDACJA
     * ================================================================*/

    protected function rules(): array
    {
        return [
            // Liczba 1-3999 (zakres zapisu rzymskiego), unikalna wśród sezonów.
            'number' => ['required', 'integer', 'min:' . Roman::MIN, 'max:' . Roman::MAX, Rule::unique('seasons', 'number')->ignore($this->seasonId)],
            'slogan' => ['nullable', 'string', 'max:120'],
            'sponsorName' => ['nullable', 'string', 'max:80'],
            // Tylko http/https: wyklucza adresy typu javascript:...
            'sponsorUrl' => ['nullable', 'url:http,https', 'max:255'],
            // Obraz (SVG odrzuca reguła "image"), kwadrat, 200-2000 px, do 2 MB.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:ratio=1/1,min_width=200,min_height=200,max_width=2000,max_height=2000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'number.unique' => __('This season number is already used.'),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'number' => __('Season number'),
            'slogan' => __('Slogan'),
            'sponsorName' => __('Sponsor name'),
            'sponsorUrl' => __('Sponsor website'),
            'logo' => __('Sponsor logo'),
        ];
    }

    /** Walidacja na żywo: numer, slogan, sponsor i logo sprawdzają się od razu po zmianie. */
    public function updated(string $property): void
    {
        if (!in_array($property, ['number', 'slogan', 'sponsorName', 'sponsorUrl', 'logo'], true)) {
            return;
        }

        try {
            $this->validateOnly($property);
        } catch (ValidationException $e) {
            // Niepoprawny plik odrzucamy od razu, żeby nie trafił do zapisu.
            if ($property === 'logo') {
                $this->reset('logo');
            }

            throw $e;
        }
    }

    /* ==================================================================
     | FORMULARZ: tworzenie i edycja
     * ================================================================*/

    public function createSeason(): void
    {
        $this->authorizeAbility(Permission::SeasonCreate);
        $this->resetForm();

        // Podpowiadamy kolejny numer po najwyższym istniejącym.
        $this->number = (string) (((int) Season::max('number')) + 1);

        Flux::modal('season-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $season = Season::findOrFail($id);

        $this->resetForm();
        $this->seasonId = $season->id;
        $this->number = (string) $season->number;
        $this->slogan = (string) $season->slogan;
        $this->sponsorName = (string) $season->sponsor_name;
        $this->sponsorUrl = (string) $season->sponsor_url;
        $this->currentLogoPath = $season->sponsor_logo_path;

        Flux::modal('season-form')->show();
    }

    /** Zapis sezonu (nowego albo edytowanego) razem z logo i wpisem w dzienniku zmian. */
    public function save(): void
    {
        $isNew = $this->seasonId === null;

        $this->authorizeAbility($isNew ? Permission::SeasonCreate : Permission::SeasonEdit);

        $this->normalize();
        $this->validate();

        $season = $isNew ? new Season() : Season::findOrFail($this->seasonId);
        $before = $isNew ? [] : $this->snapshot($season);

        $oldLogoPath = $season->sponsor_logo_path;
        $logoPath = $oldLogoPath;

        if ($this->logo) {
            // store() nadaje losową nazwę pliku, więc nazwa od użytkownika nie trafia na dysk.
            $logoPath = $this->logo->store('sponsors', 'public');
        } elseif ($this->removeLogo) {
            $logoPath = null;
        }

        try {
            $season
                ->fill([
                    'number' => (int) $this->number,
                    'slogan' => $this->slogan !== '' ? $this->slogan : null,
                    'sponsor_name' => $this->sponsorName !== '' ? $this->sponsorName : null,
                    'sponsor_url' => $this->sponsorUrl !== '' ? $this->sponsorUrl : null,
                    'sponsor_logo_path' => $logoPath,
                ])
                ->save();
        } catch (UniqueConstraintViolationException) {
            // Ktoś zajął numer między walidacją a zapisem: sprzątamy świeżo wgrany plik.
            if ($logoPath && $logoPath !== $oldLogoPath) {
                Storage::disk('public')->delete($logoPath);
            }

            $this->addError('number', __('This season number is already used.'));

            return;
        }

        // Stary plik kasujemy dopiero po udanym zapisie.
        if ($oldLogoPath && $oldLogoPath !== $logoPath) {
            Storage::disk('public')->delete($oldLogoPath);
        }

        $after = $this->snapshot($season);

        if ($isNew) {
            Audit::log('season.created', null, [], $after, $season->title);

            // Nowy sezon dostaje od razu 9 pustych kolejek (rywala i termin uzupełnia admin).
            $matchdays = $season->createMissingMatchdays();
            Audit::log('matchday.created', null, [], ['matchdays' => $matchdays], $season->title);
        } elseif ($before !== $after) {
            Audit::log('season.updated', null, $before, $after, $season->title);
        }

        Flux::modal('season-form')->close();
        $this->resetForm();
        $this->clearCaches();

        Flux::toast(text: __('Season saved.'), variant: 'success');
    }

    public function clearLogo(): void
    {
        $this->reset('logo');

        if ($this->currentLogoPath) {
            $this->removeLogo = true;
        }
    }

    public function resetForm(): void
    {
        $this->reset('seasonId', 'number', 'slogan', 'sponsorName', 'sponsorUrl', 'logo', 'currentLogoPath', 'removeLogo');
        $this->resetValidation();
    }

    private function normalize(): void
    {
        $this->number = trim($this->number);
        $this->slogan = Str::squish($this->slogan);
        $this->sponsorName = Str::squish($this->sponsorName);
        $this->sponsorUrl = trim($this->sponsorUrl);
    }

    /** Pola zapisywane w dzienniku (do porównania "było -> jest"). */
    private function snapshot(Season $season): array
    {
        return [
            'number' => $season->number,
            'slogan' => $season->slogan,
            'sponsor_name' => $season->sponsor_name,
            'sponsor_url' => $season->sponsor_url,
            'sponsor_logo' => $season->sponsor_logo_path ? basename($season->sponsor_logo_path) : null,
        ];
    }

    /* ==================================================================
     | STATUS: aktywacja i zakończenie (tylko jeden aktywny sezon)
     * ================================================================*/

    /**
     * Aktywuje wybrany sezon. Jeśli inny sezon był aktywny, zostaje zakończony.
     * Całość w transakcji, więc nigdy nie będzie dwóch aktywnych sezonów naraz.
     */
    public function activate(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $activated = DB::transaction(function () use ($id): bool {
            $season = Season::query()->lockForUpdate()->findOrFail($id);

            if ($season->status === SeasonStatus::Active) {
                return false;
            }

            // Aktywacja to osobny krok po zatwierdzeniu (lista zamknięta, terminarz gotowy).
            if ($season->status !== SeasonStatus::Approved) {
                Flux::toast(text: __('Approve the season before activating it.'), variant: 'warning');

                return false;
            }

            $others = Season::active()->where('id', '!=', $season->id)->get();

            // Poprzedni aktywny sezon kończy się normalnie: tabele końcowe i nieaktywni gracze.
            foreach ($others as $other) {
                app(FinishSeason::class)->handle($other, __('closed by activating another season'));
            }

            $old = $season->status->label();
            $season->update(['status' => SeasonStatus::Active]);

            Audit::log('season.activated', null, ['status' => $old], ['status' => SeasonStatus::Active->label()], $season->title);

            return true;
        });

        $this->clearCaches();

        if ($activated) {
            Flux::toast(text: __('Season activated.'), variant: 'success');
        }
    }

    public function finish(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        $season = Season::findOrFail($id);

        if ($season->status !== SeasonStatus::Active) {
            return;
        }

        try {
            $stats = app(FinishSeason::class)->handle($season);
        } catch (DomainException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->clearCaches();
        Flux::toast(
            text: __('Season finished.') . ' ' . __('Inactive players: :count.', ['count' => $stats['inactive']]),
            variant: 'success',
        );
    }

    /**
     * Zatwierdza sezon: zamyka listę, dodaje wszystkie boty i generuje terminarz lig.
     * Cała logika jest w App\Actions\Seasons\ApproveSeason (jedna transakcja).
     */
    public function approve(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        try {
            $result = app(ApproveSeason::class)->handle(Season::findOrFail($id));
        } catch (DomainException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->clearCaches();

        Flux::toast(
            text: __('Season approved. :leagues leagues and :fixtures fixtures were generated.', [
                'leagues' => $result['leagues'],
                'fixtures' => $result['fixtures'],
            ]),
            variant: 'success',
        );
    }

    /** Cofa zatwierdzenie (możliwe tylko przed aktywacją): usuwa ligi i terminarz. */
    public function unapprove(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);

        try {
            app(RevertApproval::class)->handle(Season::findOrFail($id));
        } catch (DomainException $e) {
            Flux::toast(text: $e->getMessage(), variant: 'danger');

            return;
        }

        $this->clearCaches();
        Flux::toast(text: __('Approval reverted. The season is a draft again.'), variant: 'success');
    }

    /**
     * Krok 1: otwiera modal Flux z pytaniem. Niczego jeszcze nie zmienia.
     * Treść pytania budujemy po stronie serwera, więc widok jest prosty.
     */
    public function confirmStatus(int $id, string $action): void
    {
        $this->authorizeAbility(Permission::SeasonEdit);
        abort_unless(in_array($action, ['approve', 'unapprove', 'activate', 'finish'], true), 422);

        $season = Season::findOrFail($id);
        $current = Season::active()->first();

        $this->statusId = $season->id;
        $this->statusAction = $action;
        $this->statusTitle = match ($action) {
            'finish' => $this->playedMatchdays($season) < Matchday::PER_SEASON ? __('Finish an incomplete season?') : __('Finish this season?'),
            'approve' => __('Approve this season?'),
            'unapprove' => __('Revert to draft?'),
            default => __('Activate this season?'),
        };

        // Przy aktywacji ostrzegamy, że poprzedni aktywny sezon zostanie zamknięty.
        $this->statusMessage = match (true) {
            $action === 'finish' && $this->playedMatchdays($season) < Matchday::PER_SEASON => __('Only :played of :total matchdays have been played. Finishing now closes the season incomplete: the remaining matchdays and unfinished competitions will not be played. Continue?', [
                'played' => $this->playedMatchdays($season),
                'total' => Matchday::PER_SEASON,
            ]),
            $action === 'finish' => $season->title,
            $action === 'approve' => __('Approval closes the team list, adds all bots and generates the league fixtures. You can revert it until the season is activated.'),
            $action === 'unapprove' => __('The league fixtures will be deleted and the team list can be edited again.'),
            $current && $current->id !== $season->id => __('Activating this season will finish :title. Continue?', ['title' => $current->title]),
            default => $season->title,
        };

        Flux::modal('season-status')->show();
    }

    /** Ile kolejek sezonu ma status "rozegrana" (do ostrzeżenia przy przedwczesnym kończeniu). */
    private function playedMatchdays(Season $season): int
    {
        return Matchday::where('season_id', $season->id)->where('status', MatchdayStatus::Played->value)->count();
    }

    /** Krok 2: użytkownik kliknął "Potwierdź" - wykonujemy właściwą akcję. */
    public function applyStatus(): void
    {
        abort_if($this->statusId === null, 422);

        match ($this->statusAction) {
            'finish' => $this->finish($this->statusId),
            'approve' => $this->approve($this->statusId),
            'unapprove' => $this->unapprove($this->statusId),
            'activate' => $this->activate($this->statusId),
            default => abort(422),
        };

        Flux::modal('season-status')->close();
        $this->reset('statusId', 'statusAction', 'statusTitle', 'statusMessage');
    }

    /* ==================================================================
     | USUWANIE (tylko szkice)
     * ================================================================*/

    public function confirmDelete(int $id): void
    {
        $this->authorizeAbility(Permission::SeasonDelete);

        $season = Season::findOrFail($id);

        if ($season->status !== SeasonStatus::Draft) {
            Flux::toast(text: __('Only draft seasons can be deleted.'), variant: 'warning');

            return;
        }

        $this->deleteId = $season->id;
        $this->deleteTitle = $season->title;

        Flux::modal('delete-season')->show();
    }

    public function delete(): void
    {
        $this->authorizeAbility(Permission::SeasonDelete);

        $season = Season::findOrFail($this->deleteId);

        // Zabezpieczenie po stronie serwera, niezależnie od tego, co pokazuje przycisk.
        abort_if($season->status !== SeasonStatus::Draft, 403);

        $title = $season->title;
        $snapshot = $this->snapshot($season);

        if ($season->sponsor_logo_path) {
            Storage::disk('public')->delete($season->sponsor_logo_path);
        }

        $season->delete();

        Audit::log('season.deleted', null, $snapshot, [], $title);

        Flux::modal('delete-season')->close();
        $this->reset('deleteId', 'deleteTitle');
        $this->clearCaches();

        Flux::toast(text: __('Season deleted.'), variant: 'success');
    }

    /* ==================================================================
     | POMOCNICZE
     * ================================================================*/

    private function clearCaches(): void
    {
        unset($this->seasons, $this->stats, $this->activeSeason);
    }

    /** Akcje Livewire to osobne żądania, więc uprawnienie sprawdzamy w każdej z nich. */
    private function authorizeAbility(Permission $permission): void
    {
        abort_unless(auth()->user()?->can($permission->value), 403);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">

    {{-- Nagłówek --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Season setup') }}</flux:heading>
            <flux:subheading>{{ __('Create seasons, choose the active one and manage the sponsor.') }}</flux:subheading>
        </div>

        @can(\App\Enums\Permission::SeasonCreate->value)
            <flux:button variant="primary" icon="plus" wire:click="createSeason">{{ __('New season') }}</flux:button>
        @endcan
    </div>

    {{-- Karty --}}
    <div class="grid gap-4 md:grid-cols-3">
        <flux:card class="flex items-center gap-4">
            <flux:icon.calendar-days class="size-8 text-zinc-400" />
            <div>
                <flux:text>{{ __('All seasons') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['all'] }}</flux:heading>
            </div>
        </flux:card>

        <flux:card class="flex items-center gap-4">
            <flux:icon.bolt class="size-8 text-green-500" />
            <div class="min-w-0">
                <flux:text>{{ __('Active season') }}</flux:text>
                @if ($this->activeSeason)
                    <flux:heading size="xl">{{ $this->activeSeason->title }}</flux:heading>
                    @if ($this->activeSeason->slogan)
                        <flux:text class="truncate text-sm">{{ $this->activeSeason->slogan }}</flux:text>
                    @endif
                @else
                    <flux:heading size="xl">&mdash;</flux:heading>
                    <flux:text class="text-sm">{{ __('No active season') }}</flux:text>
                @endif
            </div>
        </flux:card>

        <flux:card class="flex items-center gap-4">
            <flux:icon.check-circle class="size-8 text-blue-500" />
            <div>
                <flux:text>{{ __('Finished seasons') }}</flux:text>
                <flux:heading size="xl">{{ $this->stats['finished'] }}</flux:heading>
            </div>
        </flux:card>
    </div>

    {{-- Wyszukiwarka i filtr --}}
    <div class="flex flex-wrap items-center gap-4">
        <div class="w-full sm:w-80">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                :placeholder="__('Search by number, slogan or sponsor...')" clearable />
        </div>

        <div class="w-full sm:w-56">
            <flux:select wire:model.live="status">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (\App\Enums\SeasonStatus::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    {{-- Tabela sezonów --}}
    <flux:table :paginate="$this->seasons">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sortBy === 'number'" :direction="$sortDirection"
                wire:click="sort('number')">
                {{ __('Season') }}
            </flux:table.column>
            <flux:table.column>{{ __('Sponsor') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection"
                wire:click="sort('created_at')">
                {{ __('Created') }}
            </flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->seasons as $season)
                <flux:table.row :key="'season-'.$season->id">
                    <flux:table.cell>
                        <div class="text-lg font-semibold">{{ $season->title }}</div>
                        @if ($season->slogan)
                            <div class="text-sm text-zinc-500">{{ $season->slogan }}</div>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>
                        @if ($season->sponsor_name || $season->sponsor_logo_url)
                            <div class="flex items-center gap-3">
                                @if ($season->sponsor_logo_url)
                                    <img src="{{ $season->sponsor_logo_url }}" alt="{{ $season->sponsor_name }}"
                                        class="size-8 rounded object-cover" loading="lazy">
                                @endif

                                @if ($season->sponsor_url)
                                    <a href="{{ $season->sponsor_url }}" target="_blank" rel="noopener noreferrer"
                                        class="hover:underline">{{ $season->sponsor_name ?? $season->sponsor_url }}</a>
                                @else
                                    <span>{{ $season->sponsor_name }}</span>
                                @endif
                            </div>
                        @else
                            <span class="text-zinc-400">&mdash;</span>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>
                        <flux:badge size="sm" :color="$season->status->color()">{{ $season->status->label() }}
                        </flux:badge>
                    </flux:table.cell>

                    <flux:table.cell class="text-zinc-500">{{ $season->created_at?->format('Y-m-d') }}
                    </flux:table.cell>

                    <flux:table.cell align="end">
                        <flux:dropdown position="bottom" align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />

                            <flux:menu>
                                @can(\App\Enums\Permission::SeasonEdit->value)
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $season->id }})">
                                        {{ __('Edit') }}</flux:menu.item>

                                    @if ($season->status === \App\Enums\SeasonStatus::Draft)
                                        <flux:menu.item icon="check-badge"
                                            wire:click="confirmStatus({{ $season->id }}, 'approve')">
                                            {{ __('Approve season') }}
                                        </flux:menu.item>
                                    @elseif ($season->status === \App\Enums\SeasonStatus::Approved)
                                        <flux:menu.item icon="bolt"
                                            wire:click="confirmStatus({{ $season->id }}, 'activate')">
                                            {{ __('Activate') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="arrow-uturn-left"
                                            wire:click="confirmStatus({{ $season->id }}, 'unapprove')">
                                            {{ __('Revert to draft') }}
                                        </flux:menu.item>
                                    @elseif ($season->status === \App\Enums\SeasonStatus::Active)
                                        <flux:menu.item icon="flag"
                                            wire:click="confirmStatus({{ $season->id }}, 'finish')">
                                            {{ __('Finish season') }}
                                        </flux:menu.item>
                                    @endif
                                @endcan

                                @can(\App\Enums\Permission::SeasonDelete->value)
                                    @if ($season->status === \App\Enums\SeasonStatus::Draft)
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger"
                                            wire:click="confirmDelete({{ $season->id }})">{{ __('Delete') }}
                                        </flux:menu.item>
                                    @endif
                                @endcan
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-10 text-center text-zinc-500">
                        {{ $search !== '' || $status !== '' ? __('No seasons match your filters.') : __('No seasons yet. Create the first one.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    {{-- Modal: formularz sezonu --}}
    <flux:modal name="season-form" class="w-full md:w-[34rem]" @close="resetForm">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $seasonId ? __('Edit season') : __('New season') }}</flux:heading>
            </div>

            {{-- Numer z podglądem zapisu rzymskiego --}}
            <div>
                <div class="flex items-end gap-4">
                    <div class="flex-1">
                        <flux:input wire:model.live.debounce.400ms="number" type="number" min="1" max="3999"
                            :label="__('Season number')" autofocus />
                    </div>
                    <div class="min-w-20 pb-2 text-center text-3xl font-semibold tabular-nums">
                        {{ $this->romanPreview ?? '—' }}
                    </div>
                </div>
                <flux:text class="mt-1 text-sm">{{ __('Number from 1 to 3999. It is shown in Roman numerals.') }}
                </flux:text>
            </div>

            <flux:input wire:model.live.blur="slogan" :label="__('Slogan')" maxlength="120"
                :placeholder="__('e.g. Rising from the ashes')" />

            <flux:separator :text="__('Sponsor')" />

            <flux:input wire:model.live.blur="sponsorName" :label="__('Sponsor name')" maxlength="80" />

            <flux:input wire:model.live.blur="sponsorUrl" type="url" :label="__('Sponsor website')"
                placeholder="https://" maxlength="255" />

            {{-- Logo: wybór pliku, podgląd, usuwanie --}}
            <div class="space-y-2">
                <flux:label>{{ __('Sponsor logo') }}</flux:label>

                <div class="flex items-center gap-4">
                    @if ($this->logoPreview)
                        <img src="{{ $this->logoPreview }}" alt="{{ __('Sponsor logo') }}"
                            class="size-20 rounded-lg border border-zinc-200 object-cover dark:border-zinc-700">
                    @else
                        <div
                            class="flex size-20 items-center justify-center rounded-lg border border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-600">
                            <flux:icon.photo class="size-8" />
                        </div>
                    @endif

                    <div class="min-w-0 flex-1 space-y-2">
                        <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp"
                            class="block w-full text-sm text-zinc-600 file:mr-4 file:cursor-pointer file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-100 dark:hover:file:bg-zinc-600">

                        <div wire:loading wire:target="logo" class="text-sm text-zinc-500">{{ __('Uploading...') }}
                        </div>

                        @if ($this->logoPreview)
                            <flux:button type="button" size="xs" variant="ghost" icon="trash"
                                wire:click="clearLogo">
                                {{ __('Remove logo') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                <flux:text class="text-sm">{{ __('PNG, JPG or WebP, square, 200–2000 px, up to 2 MB.') }}</flux:text>
                <flux:error name="logo" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                {{-- Przycisk jest zablokowany na czas wysyłania pliku --}}
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="logo">
                    <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal: potwierdzenie aktywacji / zakończenia sezonu --}}
    <flux:modal name="season-status" class="min-w-[22rem] md:w-[26rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $statusTitle }}</flux:heading>
                <flux:text class="mt-2">{{ $statusMessage }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="applyStatus">
                    <span wire:loading.remove wire:target="applyStatus">{{ __('Confirm') }}</span>
                    <span wire:loading wire:target="applyStatus">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modal: usuwanie szkicu --}}
    <flux:modal name="delete-season" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete season?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('The season :title will be permanently deleted.', ['title' => $deleteTitle]) }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete">{{ __('Delete season') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
