<?php

namespace App\Enums;

use App\Support\EuropeanCup;

/**
 * Rodzaj rozgrywek w sezonie.
 *  - Tworzone automatycznie przy zatwierdzeniu sezonu: League (10 lig), Cup, Swiss, Legends
 *    (wszystkie zespoły ludzi, dwa pojedynki w kolejce, odpada się po dwóch porażkach, App\Support\LegendsLeague).
 *  - Ligi europejskie: przy zatwierdzeniu powstają same, gdy jest podstawa (Champions, Europa,
 *    Conference z tabel końcowych poprzedniego sezonu), poza tym admin zarządza nimi ręcznie.
 *  - Puchary europejskie (Champions, Europa, Conference): 24 zespoły, 4 grupy po 6, potem 1/8 finału … finał
 *    (App\Support\EuropeanCup).
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
        return $this === self::League;
    }

    /** Liga Mistrzów, Europy albo Konferencji (grupy i faza pucharowa, App\Support\EuropeanCup). */
    public function isEuropean(): bool
    {
        return in_array($this, [self::Champions, self::Europa, self::Conference], true);
    }

    /** Pełny skład rozgrywek ze stałym terminarzem: 10 (ligi), 24 (puchary europejskie), inaczej null. */
    public function size(): ?int
    {
        return match (true) {
            $this->isRoundRobin() => League::SIZE,
            $this->isEuropean() => EuropeanCup::TEAMS,
            default => null,
        };
    }

    /** Czy remis w meczu tej rundy rozstrzyga wcześniej zapisany typ (Puchar Polski, faza pucharowa pucharów europejskich)? */
    public function decidedByTime(int $round): bool
    {
        return $this === self::Cup || ($this->isEuropean() && EuropeanCup::isKnockoutRound($round));
    }

    /**
     * Nazwa kolejki (rundy) w tych rozgrywkach: etapy pucharu („1/8”, „Finał”), puchary europejskie
     * („Faza grupowa – kolejka 2”, „1/8 finału”), ligi i Liga Legend „Kolejka N”.
     */
    public function stageLabel(int $round): string
    {
        return match (true) {
            $this === self::Cup => KnockoutStage::labelFor($round),
            $this->isEuropean() => EuropeanCup::stageLabel($round),
            default => __('Matchday :number', ['number' => $round]),
        };
    }

    /**
     * Pod jakim typem rozgrywek liczy się dorobek zespołu w kolejce (team_scores.score_set).
     * 10 lig i Liga podwórkowa mają jeden wspólny dorobek (zapisany jako League),
     * trzy puchary europejskie też jeden (zapisany jako Champions; zespół gra tylko w jednym z nich).
     */
    public function scoreSet(): self
    {
        return match ($this) {
            self::Swiss => self::League,
            self::Europa, self::Conference => self::Champions,
            default => $this,
        };
    }

    /** Nazwa grupy rozgrywek, np. "Ligi i Liga podwórkowa", "Puchary europejskie". */
    public function scoreSetLabel(): string
    {
        return match ($this->scoreSet()) {
            self::League => __('Leagues and Liga podwórkowa'),
            self::Champions => __('European cups'),
            default => $this->label(),
        };
    }

    /** Ligi europejskie: miejsce 1, 2 i 3 lig 1-10 z poprzedniego sezonu (regulamin, punkt 10). */
    public static function europeanFor(int $place): ?self
    {
        return match ($place) {
            1 => self::Champions,
            2 => self::Europa,
            3 => self::Conference,
            default => null,
        };
    }

    /** @return array<int, self> */
    public static function manual(): array
    {
        return [self::Champions, self::Europa, self::Conference];
    }

    /** Typy tworzone i usuwane razem z zatwierdzeniem sezonu. */
    public static function automatic(): array
    {
        return [self::League, self::Cup, self::Swiss, self::Legends];
    }
}
