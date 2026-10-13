<?php

namespace App\Support;

use App\Enums\PlayerStatus;
use App\Enums\QuestionData;
use App\Enums\QuestionSubject;
use App\Models\LechPlayer;
use App\Models\Question;
use App\Models\QuestionTemplate;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Pytania o zawodników z szablonów (bank pytań 2.0, etap 2). Pytanie z szablonu to zwykłe pytanie w banku
 * z powiązaniem do szablonu i zawodnika (questions.question_template_id, lech_player_id).
 *
 * Zasady (ustalenia K5, K7):
 *  - automat tworzy i włącza pytania tylko dla grających regularnie (is_auto), zmiana statusu zawodnika je włącza i wyłącza,
 *  - admin może ręcznie dodać pytania z szablonów dla dowolnego zawodnika (is_auto = false, status ich nie zmienia),
 *  - zawodnik wyłączony z losowania: jego pytania zostają w banku, ale się nie losują (Question::scopeDrawable),
 *  - zmiana szablonu poprawia treść i ustawienia wszystkich jego pytań, także już użytych w kolejkach,
 *  - wyłączony szablon wyłącza wszystkie swoje pytania.
 */
final class QuestionTemplates
{
    /**
     * Uzgadnia pytania z szablonami i kadrą: tworzy brakujące pytania automatu, poprawia treść, włącza i wyłącza.
     *
     * @return array{created: int, updated: int, skipped: array<int, string>} skipped = treści zajęte przez inne pytania
     */
    public static function sync(): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => []];
        $players = LechPlayer::all();

        DB::transaction(function () use ($players, &$result): void {
            foreach (QuestionTemplate::all() as $template) {
                $existing = Question::where('question_template_id', $template->id)->get()->keyBy('lech_player_id');

                foreach ($players as $player) {
                    $question = $existing->get($player->id);
                    $auto = $template->is_active && $template->fits($player) && $player->status === PlayerStatus::Regular;

                    if (! $question) {
                        if ($auto) {
                            self::count($result, self::create($template, $player, true));
                        }

                        continue;
                    }

                    // Pytanie ręczne zależy tylko od szablonu: admin wybrał je świadomie, także dla innej pozycji.
                    $active = $question->is_auto ? $auto : $template->is_active;
                    $question->fill(self::attributes($template, $player) + ['is_active' => $active]);

                    if ($question->isDirty()) {
                        if ($question->isDirty('text') && self::textTaken($question->text, $question->id)) {
                            $result['skipped'][] = $question->text;

                            continue;
                        }
                        $question->save();
                        $result['updated']++;
                    }
                }
            }
        });

        return $result;
    }

    /**
     * Ręczne dodanie pytań z wybranych szablonów dla zawodnika (także rezerwowego). Istniejące pytania zostają włączone.
     *
     * @param  array<int, int>  $templateIds
     * @return array{created: int, updated: int, skipped: array<int, string>}
     */
    public static function addManual(LechPlayer $player, array $templateIds): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => []];

        DB::transaction(function () use ($player, $templateIds, &$result): void {
            foreach (QuestionTemplate::whereIn('id', $templateIds)->where('is_active', true)->get() as $template) {
                $question = Question::where('question_template_id', $template->id)->where('lech_player_id', $player->id)->first();

                if ($question) {
                    // Pytanie z automatu staje się ręcznym: zostaje włączone niezależnie od statusu.
                    $question->update(['is_active' => true, 'is_auto' => false]);
                    $result['updated']++;
                } else {
                    self::count($result, self::create($template, $player, false));
                }
            }
        });

        return $result;
    }

    /**
     * Usuwa zawodnika z kadry razem z jego nieużytymi pytaniami (K3: zawodnika z pytaniami użytymi w kolejkach nie usuwamy).
     *
     * @throws DomainException
     */
    public static function deletePlayer(LechPlayer $player): void
    {
        if ($player->questions()->whereHas('assignments')->exists()) {
            throw new DomainException(__('Questions about this player were used in matchdays. Exclude the player from the draw instead.'));
        }

        DB::transaction(function () use ($player): void {
            $player->questions()->delete();
            $player->delete();
        });
    }

    /** Usuwa szablon i jego nieużyte pytania. Pytania użyte w kolejkach zostają w banku (bez powiązania z szablonem), wyłączone. */
    public static function deleteTemplate(QuestionTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $template->questions()->whereDoesntHave('assignments')->delete();
            $template->questions()->update(['is_active' => false, 'is_auto' => false]);
            $template->delete();
        });
    }

    /**
     * Dolicza nowe pytanie albo pominiętą treść.
     *
     * @param  array{created: int, updated: int, skipped: array<int, string>}  $result
     *
     * @param-out array{created: int, updated: int, skipped: array<int, string>} $result
     */
    private static function count(array &$result, ?string $skipped): void
    {
        if ($skipped === null) {
            $result['created']++;
        } else {
            $result['skipped'][] = $skipped;
        }
    }

    /** Tworzy pytanie z szablonu dla zawodnika. Zwraca treść, gdy jest już zajęta przez inne pytanie (wtedy nic nie tworzy). */
    private static function create(QuestionTemplate $template, LechPlayer $player, bool $auto): ?string
    {
        $attributes = self::attributes($template, $player);

        if (self::textTaken($attributes['text'])) {
            return $attributes['text'];
        }

        Question::create($attributes + [
            'question_template_id' => $template->id,
            'lech_player_id' => $player->id,
            'is_auto' => $auto,
            'is_active' => true,
        ]);

        return null;
    }

    /** @return array{text: string, category_id: int|null, subject: QuestionSubject, match_types: array<int, string>|null, weights: array<int, string>|null, data_level: QuestionData} pola pytania przepisywane z szablonu */
    private static function attributes(QuestionTemplate $template, LechPlayer $player): array
    {
        return [
            'text' => $template->textFor($player),
            'category_id' => $template->category_id,
            'subject' => QuestionSubject::Player,
            'match_types' => $template->match_types,
            'weights' => $template->weights,
            'data_level' => $template->data_level,
        ];
    }

    /** Czy treść ma już inne pytanie w banku (treść pytania jest unikalna). */
    private static function textTaken(string $text, ?int $exceptId = null): bool
    {
        return Question::where('text', $text)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
    }
}
