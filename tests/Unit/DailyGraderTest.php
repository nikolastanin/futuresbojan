<?php

use App\Manual\DailyGrader;

function gTrade(float $pnl, ?float $hold = 120.0, string $symbol = 'TAO_USDT'): array
{
    return [
        'symbol' => $symbol, 'direction' => 'LONG', 'pnl' => $pnl, 'leverage' => 100,
        'opened_at' => 1_000_000, 'closed_at' => 2_000_000, 'hold_minutes' => $hold,
    ];
}

function gEvent(string $type, array $extra = []): array
{
    return array_merge([
        'type' => $type, 'symbol' => 'TAO_USDT', 'direction' => 'LONG', 'details' => null, 'context' => null, 'at' => 1_000,
    ], $extra);
}

function gEntry(string $quality, ?bool $withHtf = true, int $at = 1_000): array
{
    return gEvent('entry', ['at' => $at, 'context' => ['zone_quality' => $quality, 'with_higher_tf' => $withHtf]]);
}

function component(array $grade, string $name): array
{
    foreach ($grade['components'] as $c) {
        if ($c['name'] === $name) {
            return $c;
        }
    }

    throw new RuntimeException("No component {$name}");
}

describe('with nothing to grade', function () {
    it('returns no score, letter or components', function () {
        $grade = (new DailyGrader)->grade('2026-10-06', [], []);

        expect($grade['score'])->toBeNull()
            ->and($grade['letter'])->toBeNull()
            ->and($grade['partial'])->toBeFalse()
            ->and($grade['missing'])->toBe(['result', 'risk', 'patience', 'process']);
    });
});

describe('result', function () {
    it('scores on profit factor: all wins 100, profit factor 2 is 100, 1 is 50, all losses 0', function () {
        $score = fn (array $pnls) => component((new DailyGrader)->grade('d', array_map('gTrade', $pnls), []), 'result')['score'];

        expect($score([10, 20]))->toBe(100)
            ->and($score([40, -20]))->toBe(100)
            ->and($score([20, -20]))->toBe(50)
            ->and($score([-5, -10]))->toBe(0);
    });

    it('reports the net PnL as a flag', function () {
        $grade = (new DailyGrader)->grade('d', [gTrade(10), gTrade(-25.5)], []);

        expect(array_column($grade['flags'], 'text'))->toContain('Net realized PnL was −$15.50 across 2 closed trades.');
    });
});

describe('risk', function () {
    it('rewards wins that are bigger than losses', function () {
        $risk = component((new DailyGrader)->grade('d', [gTrade(20), gTrade(-10)], []), 'risk');

        expect($risk['score'])->toBe(100);
    });

    it('penalises an average loss bigger than the average win', function () {
        $grade = (new DailyGrader)->grade('d', [gTrade(5), gTrade(-10)], []);

        // payoff 50*5/10 = 25, no outsized loss -> round(0.6*25 + 0.4*100) = 55
        expect(component($grade, 'risk')['score'])->toBe(55)
            ->and(array_column($grade['flags'], 'text'))->toContain('Your average loss ($10.00) is bigger than your average win ($5.00).');
    });

    it('flags one outsized loss', function () {
        $grade = (new DailyGrader)->grade('d', [gTrade(30), gTrade(-10), gTrade(-10), gTrade(-60)], []);

        expect(array_column($grade['flags'], 'text'))->toContain('Your biggest loss ($60.00) was 2.3× your average loss.');
    });

    it('gives full marks to a day with no losses', function () {
        expect(component((new DailyGrader)->grade('d', [gTrade(5), gTrade(7)], []), 'risk')['score'])->toBe(100);
    });
});

describe('patience', function () {
    it('counts trades closed in under 15 minutes', function () {
        $grade = (new DailyGrader)->grade('d', [gTrade(1, 5), gTrade(1, 10), gTrade(1, 120), gTrade(1, 300)], []);

        // 2 of 4 hasty -> 50; 4 trades is under the comfort line -> 100.
        // Half the weakest (50) + half the mean (75) = 62.5 -> 63.
        expect(component($grade, 'patience')['score'])->toBe(63)
            ->and(array_column($grade['flags'], 'text'))->toContain('2 of 4 closed trades were held under 15 minutes.');
    });

    it('ignores trades whose hold time is unknown instead of guessing', function () {
        $grade = (new DailyGrader)->grade('d', [gTrade(1, null), gTrade(1, null)], []);

        expect(component($grade, 'patience')['detail'])->toBe(['trade_count' => 100]);
    });

    it('takes points off for over-trading past 8 trades', function () {
        $grade = (new DailyGrader)->grade('d', array_map(fn () => gTrade(1, 120), range(1, 10)), []);

        // quick closes 100, trade count 100 - 8*2 = 84 -> half the weakest (84) + half the mean (92) = 88.
        expect(component($grade, 'patience')['detail']['trade_count'])->toBe(84)
            ->and(component($grade, 'patience')['score'])->toBe(88);
    });

    it('penalises early unlocks and attempts to touch a locked position', function () {
        $events = [gEvent('lock'), gEvent('unlock_early'), gEvent('blocked_attempt'), gEvent('blocked_attempt')];
        $grade  = (new DailyGrader)->grade('d', [], $events);

        // 100 - 25 (one early unlock) - 20 (two blocked attempts) = 55, the only part -> 55.
        expect(component($grade, 'patience')['score'])->toBe(55)
            ->and(array_column($grade['flags'], 'text'))->toContain('1 lock was released before it expired.')
            ->and(array_column($grade['flags'], 'text'))->toContain('2 attempts to touch a position you had locked.');
    });

    it('credits a lock that was left alone', function () {
        $grade = (new DailyGrader)->grade('d', [], [gEvent('lock')]);

        expect(component($grade, 'patience')['score'])->toBe(100)
            ->and(array_column($grade['flags'], 'text'))->toContain('Every lock you set was left alone.');
    });

    it('does not penalise releasing an indefinite lock', function () {
        $grade = (new DailyGrader)->grade('d', [], [gEvent('lock'), gEvent('unlock')]);

        expect(component($grade, 'patience')['score'])->toBe(100);
    });
});

describe('process', function () {
    it('scores each entry by how well it lined up with the plan, and marks down going against 4H', function () {
        $events = [gEntry('confirmed_zone'), gEntry('unconfirmed_zone'), gEntry('no_zone', false)];
        $grade  = (new DailyGrader)->grade('d', [], $events);

        // 100, 55, and 25-20 = 5 -> mean 53.33 -> 53
        expect(component($grade, 'process')['score'])->toBe(53)
            ->and(component($grade, 'process')['detail']['at_confirmed_zone'])->toBe(1)
            ->and(array_column($grade['flags'], 'text'))->toContain('1 entry had no plan zone near price.')
            ->and(array_column($grade['flags'], 'text'))->toContain('1 entry went against the 4H backdrop.');
    });

    it('is left out when no entry was logged', function () {
        $grade = (new DailyGrader)->grade('d', [gTrade(5)], []);

        expect(component($grade, 'process')['score'])->toBeNull()
            ->and($grade['missing'])->toBe(['process'])
            ->and($grade['partial'])->toBeTrue();
    });

    it('says when an entry had no analysis snapshot instead of scoring it', function () {
        $grade = (new DailyGrader)->grade('d', [], [gEvent('entry'), gEntry('confirmed_zone')]);

        expect(component($grade, 'process')['detail']['entries_scored'])->toBe(1)
            ->and(array_column($grade['flags'], 'text'))->toContain('1 entry could not be scored (no analysis snapshot was captured).');
    });

    it('counts an entry as protected when a stop or take-profit follows within 30 minutes', function () {
        $events = [
            gEntry('confirmed_zone', true, 1_000),
            gEvent('sl_tp', ['at' => 1_000 + 20 * 60]),
            gEntry('confirmed_zone', true, 10_000),
            gEvent('sl_tp', ['at' => 10_000 + 45 * 60]), // too late to count
        ];

        $process = component((new DailyGrader)->grade('d', [], $events), 'process');

        expect($process['detail']['protected'])->toBe(1);
    });
});

describe('the overall grade', function () {
    it('does not let a profitable day with a hasty close walk away with an A', function () {
        // A win and a small loss, but the loss was closed after 5 minutes. Result and risk are
        // perfect; patience should pull the day below an A.
        $grade = (new DailyGrader)->grade('d', [gTrade(20, 120), gTrade(-10, 5)], []);

        // result 100, risk 100, patience 63 -> (2000 + 2500 + 1890) / 75 = 85.2 -> 85
        expect($grade['score'])->toBe(85)->and($grade['letter'])->toBe('B');
    });

    it('re-weights over the components that exist and marks the grade partial', function () {
        // Result: pf 0.5 -> 25.  Risk: payoff 25 -> round(.6*25+.4*100) = 55.
        // Patience: 1 of 2 hasty -> 50, trade count 100 -> half the weakest + half the mean = 63.
        // Process: none.
        $grade = (new DailyGrader)->grade('d', [gTrade(10, 5), gTrade(-20, 600)], []);

        // (25*20 + 55*25 + 63*30) / (20+25+30) = 3765 / 75 = 50.2 -> 50
        expect($grade['score'])->toBe(50)
            ->and($grade['letter'])->toBe('F')
            ->and($grade['partial'])->toBeTrue();
    });

    it('is not partial when every component has data', function () {
        $grade = (new DailyGrader)->grade('d', [gTrade(20, 120), gTrade(-10, 120)], [gEntry('confirmed_zone')]);

        expect($grade['partial'])->toBeFalse()
            ->and($grade['missing'])->toBe([])
            ->and($grade['score'])->toBe(100)
            ->and($grade['letter'])->toBe('A');
    });

    it('grades a day with decisions but no closed trades from what it has', function () {
        $grade = (new DailyGrader)->grade('d', [], [gEntry('confirmed_zone'), gEvent('lock')]);

        expect($grade['missing'])->toBe(['result', 'risk'])
            ->and($grade['score'])->toBe(100)
            ->and($grade['partial'])->toBeTrue();
    });

    it('maps scores to letters at the boundaries', function () {
        $g = new DailyGrader;

        expect([$g->letter(100), $g->letter(90), $g->letter(89), $g->letter(80), $g->letter(79), $g->letter(70), $g->letter(69), $g->letter(60), $g->letter(59), $g->letter(0)])
            ->toBe(['A', 'A', 'B', 'B', 'C', 'C', 'D', 'D', 'F', 'F']);
    });

    it('lists warnings before information before good news', function () {
        $grade = (new DailyGrader)->grade('d', [gTrade(5, 120), gTrade(-10, 3)], [gEvent('lock')]);

        $tones = array_column($grade['flags'], 'tone');

        expect($tones)->toBe(array_values(array_merge(
            array_filter($tones, fn ($t) => $t === 'warn'),
            array_filter($tones, fn ($t) => $t === 'info'),
            array_filter($tones, fn ($t) => $t === 'good'),
        )));
    });

    it('marks hasty trades in the trade list', function () {
        $grade = (new DailyGrader)->grade('d', [gTrade(1, 5), gTrade(1, 200), gTrade(1, null)], []);

        expect(array_column($grade['trades'], 'hasty'))->toBe([true, false, false]);
    });
});
