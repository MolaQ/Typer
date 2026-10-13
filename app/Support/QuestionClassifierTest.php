<?php

use App\Enums\QuestionData;
use App\Enums\QuestionMatchType;
use App\Enums\QuestionSubject;
use App\Support\QuestionClassifier;

it('guesses the category from the text', function () {
    expect(QuestionClassifier::category('Czy zawodnik Lecha otrzyma czerwoną kartkę?'))->toBe('cards')
        ->and(QuestionClassifier::category('Czy Lech będzie miał co najmniej 60% posiadania piłki?'))->toBe('possession')
        ->and(QuestionClassifier::category('Czy Lech wykona co najmniej 6 rzutów rożnych?'))->toBe('corners')
        ->and(QuestionClassifier::category('Czy gola dla Lecha strzeli pomocnik?'))->toBe('players')
        ->and(QuestionClassifier::category('Czy Lech strzeli gola w pierwszej połowie?'))->toBe('goals');
});

it('guesses the subject and the required data', function () {
    expect(QuestionClassifier::subject('Czy rywal strzeli gola?'))->toBe(QuestionSubject::Opponent)
        ->and(QuestionClassifier::subject('Czy Lech odda więcej strzałów niż rywal?'))->toBe(QuestionSubject::Match)
        ->and(QuestionClassifier::subject('Czy Lech zachowa czyste konto?'))->toBe(QuestionSubject::Lech)
        ->and(QuestionClassifier::data('Czy Lech odda co najmniej 5 strzałów celnych?'))->toBe(QuestionData::Stats)
        ->and(QuestionClassifier::data('Czy Lech strzeli gola głową?'))->toBe(QuestionData::Basic);
});

it('allows friendlies only for basic-data questions', function () {
    expect(QuestionClassifier::matchTypes(QuestionData::Basic))->toContain(QuestionMatchType::Friendly->value)
        ->and(QuestionClassifier::matchTypes(QuestionData::Stats))->not->toContain(QuestionMatchType::Friendly->value);
});
