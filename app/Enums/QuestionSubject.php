<?php

namespace App\Enums;

/**
 * Czego dotyczy pytanie bonusowe (bank pytań 2.0): Lech, rywal, cały mecz, zawodnik Lecha, sztab,
 * wychowankowie albo narodowości zawodników. Pytania o zawodników pochodzą z kadry Lecha (etap 2).
 */
enum QuestionSubject: string
{
    case Lech = 'lech';
    case Opponent = 'opponent';
    case Match = 'match';
    case Player = 'player';
    case Staff = 'staff';
    case Academy = 'academy';
    case Nationality = 'nationality';

    public function label(): string
    {
        return match ($this) {
            self::Lech => __('Lech'),
            self::Opponent => __('Opponent'),
            self::Match => __('Whole match'),
            self::Player => __('Lech player'),
            self::Staff => __('Coaching staff'),
            self::Academy => __('Academy players'),
            self::Nationality => __('Nationalities'),
        };
    }
}
