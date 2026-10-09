<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UniqueTeamValue implements ValidationRule
{
    private const COLUMNS = ['team_name', 'team_short_name', 'team_abbr'];

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
        $taken = DB::table('users')
            ->whereRaw("LOWER({$this->column}) = ?", [mb_strtolower(trim((string) $value))])
            ->when($this->ignoreUserId, fn ($query) => $query->where('id', '!=', $this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail(__('This :attribute is already taken.'));
        }
    }
}
