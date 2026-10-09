<?php

namespace App\Enums;

/**
 * Rozgrywki, w których Lech gra mecz kolejki (pole matchdays.competition).
 * Jedno źródło prawdy dla selecta w panelu „Kolejki” i dla seedera sezonu testowego
 * (Database\Seeders\DemoSeasonSeeder). W bazie zapisujemy wartość enuma (np. 'league'),
 * a na stronach pokazujemy przetłumaczoną etykietę (label()).
 *
 * Starsze kolejki mogły mieć wpisany dowolny tekst; Matchday::competitionLabel() pokazuje go wtedy bez zmian.
 */
enum MatchCompetition: string
{
    case League = 'league';
    case ChampionsLeague = 'champions_league';
    case EuropaLeague = 'europa_league';
    case ConferenceLeague = 'conference_league';
    case PolishCup = 'polish_cup';
    case Friendly = 'friendly';

    /** Domyślna opcja selecta: mecz ligowy. */
    public const DEFAULT = self::League;

    /** Etykieta do wyświetlenia (tłumaczenie z lang/pl.json). */
    public function label(): string
    {
        return match ($this) {
            self::League => __('League match'),
            self::ChampionsLeague => __('Champions League'),
            self::EuropaLeague => __('Europa League'),
            self::ConferenceLeague => __('Conference League'),
            self::PolishCup => __('Polish Cup'),
            self::Friendly => __('Friendly match'),
        };
    }

    /**
     * Opcje do selecta: wartość => etykieta.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
