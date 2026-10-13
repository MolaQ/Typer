<?php

namespace Database\Seeders;

use App\Enums\MatchWeight;
use App\Enums\PlayerPosition;
use App\Enums\QuestionData;
use App\Models\QuestionCategory;
use App\Models\QuestionTemplate;
use App\Support\QuestionClassifier;
use App\Support\QuestionTemplates;
use Illuminate\Database\Seeder;

/**
 * Szablony pytań o zawodników na start (ustalenie K6) i od razu pytania z nich dla grających regularnie
 * (QuestionTemplates::sync). Wymaga kadry (LechSquadSeeder). Można uruchamiać wielokrotnie.
 *
 *   php artisan db:seed --class=QuestionTemplatesSeeder
 */
class QuestionTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = QuestionCategory::pluck('id', 'key');

        foreach (self::templates() as [$text, $category, $positions]) {
            QuestionTemplate::firstOrCreate(['text' => $text], [
                'category_id' => $categories[$category] ?? null,
                'positions' => array_map(fn (PlayerPosition $p) => $p->value, $positions),
                // Pytania z protokołu meczu: pasują też do sparingów.
                'match_types' => QuestionClassifier::matchTypes(QuestionData::Basic),
                'weights' => MatchWeight::values(),
                'data_level' => QuestionData::Basic,
            ]);
        }

        $result = QuestionTemplates::sync();
        $this->command?->info("Szablony pytań: {$result['created']} pytań o zawodników.");
    }

    /** @return array<int, array{0: string, 1: string, 2: array<int, PlayerPosition>}> treść, klucz kategorii, pozycje (puste = wszystkie) */
    public static function templates(): array
    {
        $outfield = [PlayerPosition::Defender, PlayerPosition::Midfielder, PlayerPosition::Forward];

        return [
            ['Czy :zawodnik dostanie żółtą kartkę?', 'cards', []],
            ['Czy :zawodnik dostanie czerwoną kartkę?', 'cards', []],
            ['Czy :zawodnik strzeli gola?', 'players', $outfield],
            ['Czy :zawodnik zaliczy asystę?', 'players', $outfield],
            ['Czy :zawodnik strzeli gola lub zaliczy asystę?', 'players', $outfield],
            ['Czy :zawodnik strzeli gola głową?', 'players', $outfield],
            ['Czy :zawodnik strzeli gola z rzutu karnego?', 'penalties', [PlayerPosition::Midfielder, PlayerPosition::Forward]],
            ['Czy :zawodnik wejdzie na boisko z ławki rezerwowych?', 'substitutions', []],
            ['Czy :zawodnik zagra pełne 90 minut?', 'substitutions', []],
            ['Czy :zawodnik zachowa czyste konto?', 'goalkeeper', [PlayerPosition::Goalkeeper]],
        ];
    }
}
