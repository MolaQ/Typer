<?php

namespace App\Models;

use App\Notifications\VerificationCode;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $premium_until
 * @property int|null $default_tip_lech
 * @property int|null $default_tip_opponent
 * @property string|null $verification_code
 * @property Carbon|null $verification_code_expires_at
 */
#[Fillable(['name', 'email', 'password', 'team_name', 'team_short_name', 'team_abbr'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'verification_code'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'premium_until' => 'datetime',
            'verification_code_expires_at' => 'datetime',
            'default_tip_lech' => 'integer',
            'default_tip_opponent' => 'integer',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /** Ile minut jest ważny kod potwierdzający e-mail. */
    public const VERIFICATION_CODE_MINUTES = 60;

    /**
     * Zamiast linku wysyłamy 6-cyfrowy kod (Fortify woła tę metodę po rejestracji i przy „Wyślij ponownie”).
     * Kod zapisujemy jawnie, bo admin pokazuje go na stronie Użytkownicy.
     */
    public function sendEmailVerificationNotification(): void
    {
        $code = $this->newVerificationCode();

        $this->notify(new VerificationCode($code, self::VERIFICATION_CODE_MINUTES));
    }

    /** Nowy kod potwierdzający (bez wysyłki), ważny VERIFICATION_CODE_MINUTES minut. */
    public function newVerificationCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->forceFill([
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(self::VERIFICATION_CODE_MINUTES),
        ])->save();

        return $code;
    }

    /** Czy podany kod pasuje i jest jeszcze ważny? */
    public function verificationCodeMatches(string $code): bool
    {
        return $this->verification_code !== null
            && $this->verification_code_expires_at?->isFuture()
            && hash_equals($this->verification_code, $code);
    }

    /** Potwierdza adres i kasuje kod. */
    public function confirmEmail(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'verification_code' => null,
            'verification_code_expires_at' => null,
        ])->save();
    }

    /** Wpłaty gracza (premium i wsparcie). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
