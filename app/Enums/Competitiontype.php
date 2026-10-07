<?php

namespace App\Enums;

/**
 * Rodzaj rozgrywek w sezonie. Na razie tylko liga (10 lig po 10 zespołów).
 * W etapie 8b dojdą: puchar, liga podwórkowa (system szwajcarski) i rozgrywki
 * tworzone ręcznie (europejskie, Liga Legend, Złota Liga).
 */
enum CompetitionType: string
{
    case League = 'league';

    public function label(): string
    {
        return match ($this) {
            self::League => __('League'),
        };
    }
}
