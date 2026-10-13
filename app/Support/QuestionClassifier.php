<?php

namespace App\Support;

use App\Enums\QuestionData;
use App\Enums\QuestionMatchType;
use App\Enums\QuestionSubject;

/**
 * Wstępne przypisanie pytania z banku do kategorii, „dotyczy” i wymaganych danych po słowach w treści.
 * Używane raz: przy migracji istniejących pytań i w seederze przykładowych pytań. Admin poprawia w panelu.
 */
final class QuestionClassifier
{
    /**
     * Słowa kluczowe => klucz kategorii (QuestionCategory::DEFAULTS). Kolejność ma znaczenie: pierwsze trafienie wygrywa.
     *
     * @var array<string, string>
     */
    private const CATEGORY_WORDS = [
        'kartk' => 'cards',
        'posiadani' => 'possession',
        'rożn' => 'corners',
        'karn' => 'penalties',
        'bramkarz' => 'goalkeeper',
        'czyste konto' => 'goalkeeper',
        'faul' => 'fouls',
        'spalon' => 'fouls',
        'z ławki' => 'substitutions',
        'zmian' => 'substitutions',
        'strzał' => 'shots',
        'słupek' => 'shots',
        'prowadził' => 'match_flow',
        'wygra ' => 'match_flow',
        'wyrówna' => 'match_flow',
        'pierwszego gola' => 'match_flow',
        'samobój' => 'match_flow',
        'pomocnik' => 'players',
        'obrońca' => 'players',
        'napastnik' => 'players',
        'zawodnik' => 'players',
    ];

    /** Słowa oznaczające pytanie wymagające statystyk meczu (nie da się go rozstrzygnąć z protokołu). */
    private const STATS_WORDS = ['posiadani', 'strzał', 'rożn', 'faul', 'spalon', 'słupek', 'obroni'];

    /** Klucz kategorii dla treści pytania (domyślnie „gole”). */
    public static function category(string $text): string
    {
        $lower = mb_strtolower($text);

        foreach (self::CATEGORY_WORDS as $word => $key) {
            if (str_contains($lower, $word)) {
                return $key;
            }
        }

        return 'goals';
    }

    /** Czego dotyczy pytanie: Lech, rywal albo cały mecz (oba zespoły w treści). */
    public static function subject(string $text): QuestionSubject
    {
        $lower = mb_strtolower($text);
        $lech = str_contains($lower, 'lech');
        $opponent = str_contains($lower, 'rywal');

        return match (true) {
            $lech && $opponent => QuestionSubject::Match,
            $opponent => QuestionSubject::Opponent,
            default => QuestionSubject::Lech,
        };
    }

    public static function data(string $text): QuestionData
    {
        $lower = mb_strtolower($text);

        foreach (self::STATS_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return QuestionData::Stats;
            }
        }

        return QuestionData::Basic;
    }

    /**
     * Rodzaje meczów: liga, PP i Europa zawsze; sparing tylko dla pytań z podstawowymi danymi (bez statystyk).
     *
     * @return array<int, string>
     */
    public static function matchTypes(QuestionData $data): array
    {
        return $data === QuestionData::Basic
            ? QuestionMatchType::values()
            : array_values(array_diff(QuestionMatchType::values(), [QuestionMatchType::Friendly->value]));
    }
}
