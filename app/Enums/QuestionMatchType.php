<?php

namespace App\Enums;

/**
 * Rodzaj meczu Lecha, do którego pasuje pytanie (bank pytań 2.0). To grupy rozgrywek meczu kolejki
 * (MatchCompetition): liga, Puchar Polski, puchary europejskie (LM, LE, LK razem) i sparing.
 * Sparing losuje tylko z pytań oznaczonych jako „sparing” (bez statystyk, często bez transmisji).
 */
enum QuestionMatchType: string
{
    case League = 'league';
    case PolishCup = 'polish_cup';
    case European = 'european';
    case Friendly = 'friendly';

    public function label(): string
    {
        return match ($this) {
            self::League => __('League'),
            self::PolishCup => __('Polish Cup'),
            self::European => __('European cups'),
            self::Friendly => __('Friendly'),
        };
    }

    /** Grupa dla rozgrywek meczu kolejki (null = brak rozgrywek albo dowolny stary tekst). */
    public static function forCompetition(MatchCompetition|string|null $competition): ?self
    {
        $competition = $competition instanceof MatchCompetition ? $competition : MatchCompetition::tryFrom((string) $competition);

        return match ($competition) {
            MatchCompetition::League => self::League,
            MatchCompetition::PolishCup => self::PolishCup,
            MatchCompetition::ChampionsLeague, MatchCompetition::EuropaLeague, MatchCompetition::ConferenceLeague => self::European,
            MatchCompetition::Friendly => self::Friendly,
            null => null,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
