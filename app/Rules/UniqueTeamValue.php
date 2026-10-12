<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UniqueTeamValue implements ValidationRule
{
    private const COLUMNS = ['team_name'];

    public function __construct(
        private string $column,
        private ?int $ignoreUserId = null,
    ) {
        // Nazwa kolumny trafia do SQL, więc dopuszczamy tylko znane wartości.
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("Unsupported column: {$column}");
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $needle = mb_strtolower(trim((string) $value));

        // Nazwa zespołu musi być unikalna wśród graczy i botów (bez względu na wielkość liter).
        $taken = DB::table('users')
            ->whereRaw("LOWER({$this->column}) = ?", [$needle])
            ->when($this->ignoreUserId, fn ($query) => $query->where('id', '!=', $this->ignoreUserId))
            ->exists()
            || DB::table('bots')->whereRaw('LOWER(name) = ?', [$needle])->exists();

        if ($taken) {
            $fail(__('This :attribute is already taken.'));
        }
    }
}
