<?php

namespace App\Enums;

/**
 * Rodzaj rozgrywek w sezonie.
 *  - Tworzone automatycznie przy zatwierdzeniu sezonu: League (10 lig), Cup, Swiss.
 *  - Tworzone ręcznie przez admina (skład zależy od wyników z poprzedniego sezonu,
 *    wpłat i punktów Hall of Fame): Champions, Europa, Conference, Legends, Golden.
 * Nazwy rozgrywek to polskie nazwy własne, więc nie przechodzą przez tłumaczenia.
 */
enum CompetitionType: string
{
    case League = 'league';
    case Cup = 'cup';
    case Swiss = 'swiss';
    case Champions = 'champions';
    case Europa = 'europa';
    case Conference = 'conference';
    case Legends = 'legends';
    case Golden = 'golden';

    public function label(): string
    {
        return match ($this) {
            self::League => __('League'),
            self::Cup => 'Puchar Polski',
            self::Swiss => 'Liga podwórkowa',
            self::Champions => 'Liga Mistrzów',
            self::Europa => 'Liga Europy',
            self::Conference => 'Liga Konferencji',
            self::Legends => 'Liga Legend',
            self::Golden => 'Złota Liga',
        };
    }

    /** Rozgrywki, które admin tworzy ręcznie na stronie "Rozgrywki". */
    public function isManual(): bool
    {
        return in_array($this, self::manual(), true);
    }

    /** Liga 10 zespołów każdy z każdym (9 kolejek, schemat z App\Support\LeagueSchedule). */
    public function isRoundRobin(): bool
    {
        return in_array($this, [self::League, self::Champions, self::Europa, self::Conference], true);
    }

    /**
     * Typ, którego zestaw pytań bonusowych obowiązuje w tych rozgrywkach.
     * 10 lig i Liga podwórkowa mają jeden wspólny zestaw (zapisany jako League).
     */
    public function questionSet(): self
    {
        return $this === self::Swiss ? self::League : $this;
    }

    /** Nazwa zestawu pytań, np. "Ligi i Liga podwórkowa". */
    public function questionSetLabel(): string
    {
        return $this->questionSet() === self::League ? __('Leagues and Liga podwórkowa') : $this->label();
    }

    /** @return array<int, self> */
    public static function manual(): array
    {
        return [self::Champions, self::Europa, self::Conference, self::Legends, self::Golden];
    }

    /** Typy tworzone i usuwane razem z zatwierdzeniem sezonu. */
    public static function automatic(): array
    {
        return [self::League, self::Cup, self::Swiss];
    }
}
