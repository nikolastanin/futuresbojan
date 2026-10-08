<?php

namespace App\Manual;

/**
 * Builds the prompt for the comment beside each open position and returns the model's
 * structured answer, cleaned up.
 *
 * What the model sees is measured here, not taken on trust from the page: the timeframes,
 * levels, plan zones and candle flags come from AnalysisExtrasService on the server, and the
 * indicator snapshot, the positions and the live risk figures are what the dashboard already
 * shows (the page is the only one that holds the trader's live positions). The model is
 * only allowed to cite prices from a list built here, and the price it says the comment
 * hinges on is checked against that list, so it cannot invent a level. Advisory only —
 * nothing here touches an order or a lock.
 */
class PositionBriefService
{
    private const TIMEOUT_SECONDS = 30;

    // DeepSeek Flash list prices at the peak-hours rate (2x off-peak), so the cost shown to
    // the user is a ceiling rather than an underestimate. Same figures as HedgeAdvisorService.
    private const INPUT_COST_PER_MILLION  = 0.30;
    private const OUTPUT_COST_PER_MILLION = 1.20;

    /** A price the model names counts as a listed one when it is within this fraction of it. */
    private const PRICE_MATCH_TOLERANCE = 0.0005;

    /** A market level further than this fraction from today's price is not offered to the model. */
    private const MARKET_LEVEL_REACH = 0.12;

    private const MAX_NOTE_LENGTH = 320;

    public function __construct(
        private AnalysisExtrasService $extras,
        private HedgeAdvisorService $advisor,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $positions  The trader's open legs in this coin (one or both sides).
     * @param  array<string, mixed>  $signal  The indicator snapshot the dashboard already holds for the coin.
     * @param  array<string, mixed>  $risk  The live risk figures the dashboard computed (see riskLines()).
     * @return array{language: string, long_note: string, short_note: string, watch_price: ?float, watch_label: ?string, watch_when: string, estimated_cost_usd: float}
     */
    public function read(string $symbol, string $language, array $positions, array $signal = [], array $risk = []): array
    {
        $language = $language === 'sr' ? 'sr' : 'en';
        $extras   = $this->extras->forSymbol($symbol);
        $citable  = self::citablePrices($extras, $signal, $positions, $risk);

        $response = (new PositionBriefAgent)->prompt(
            $this->buildPrompt($symbol, $language, $extras, $signal, $positions, $risk, $citable),
            timeout: self::TIMEOUT_SECONDS,
        );

        $directions = array_map(fn ($p) => strtoupper((string) ($p['direction'] ?? '')), $positions);
        $longNote   = self::cleanNote($response['long_note'] ?? null, in_array('LONG', $directions, true));
        $shortNote  = self::cleanNote($response['short_note'] ?? null, in_array('SHORT', $directions, true));

        if ($longNote === '' && $shortNote === '') {
            throw new \RuntimeException('The assistant had nothing usable to say.');
        }

        $watch = self::matchCitable($response['watch_price'] ?? null, $citable);

        $in  = (int) ($response->usage->promptTokens ?? 0);
        $out = (int) ($response->usage->completionTokens ?? 0);

        return [
            'language'           => $language,
            'long_note'          => $longNote,
            'short_note'         => $shortNote,
            'watch_price'        => $watch['price'] ?? null,
            'watch_label'        => $watch['label'] ?? null,
            'watch_when'         => $watch === null ? '' : self::cleanNote($response['watch_when'] ?? null, true, 60),
            'estimated_cost_usd' => round(
                ($in / 1_000_000) * self::INPUT_COST_PER_MILLION + ($out / 1_000_000) * self::OUTPUT_COST_PER_MILLION,
                6,
            ),
        ];
    }

    /**
     * Every price the comments may cite, with a label: the plan's zone edges, the levels the
     * dashboard shows (only those within reach of today's price — a level 30% away is no
     * target for a comment about waiting), the pair's break-even, and the trader's own
     * entries, liquidation prices and armed stops. A price outside this list cannot appear
     * as the watch price.
     *
     * @param  array<string, mixed>  $extras
     * @param  array<string, mixed>  $signal
     * @param  array<int, array<string, mixed>>  $positions
     * @param  array<string, mixed>  $risk
     * @return array<string, float>  label => price
     */
    public static function citablePrices(array $extras, array $signal, array $positions, array $risk): array
    {
        $reference = is_numeric($extras['plan']['price'] ?? null) ? (float) $extras['plan']['price'] : null;
        $prices    = [];
        $add       = function (string $label, mixed $price) use (&$prices) {
            if (is_numeric($price) && (float) $price > 0 && ! isset($prices[$label])) {
                $prices[$label] = (float) $price;
            }
        };
        $addMarket = function (string $label, mixed $price) use ($add, $reference) {
            if (is_numeric($price) && ($reference === null || abs((float) $price - $reference) / $reference <= self::MARKET_LEVEL_REACH)) {
                $add($label, $price);
            }
        };

        foreach ($extras['plan']['zones'] ?? [] as $zone) {
            $name = ucfirst((string) ($zone['side'] ?? '')).' zone '.($zone['number'] ?? '');

            $addMarket("{$name} lower edge", $zone['low'] ?? null);

            if (($zone['high'] ?? null) != ($zone['low'] ?? null)) {
                $addMarket("{$name} upper edge", $zone['high'] ?? null);
            }
        }

        foreach ($extras['levels'] ?? [] as $key => $price) {
            $addMarket(str_replace('_', ' ', (string) $key), $price);
        }

        foreach ($signal['levels'] ?? [] as $key => $price) {
            $addMarket(str_replace('_', ' ', (string) $key), $price);
        }

        // The trader's own prices stay on the list however far away they are.
        $add('combined break-even', $risk['coin']['break_even'] ?? null);

        foreach ($positions as $p) {
            $side = strtolower((string) ($p['direction'] ?? 'position'));

            $add("your {$side} entry", $p['entry'] ?? null);
            $add("your {$side} liquidation price", $p['liquidation_price'] ?? null);
            $add("your {$side} stop-loss", $p['stop_loss'] ?? null);
            $add("your {$side} take-profit", $p['take_profit'] ?? null);
        }

        return $prices;
    }

    /**
     * The listed price a model-written one refers to, or null. Accepts "305", "305.00" and
     * the Serbian "305,00"; a price that is not close to one on the list is dropped.
     *
     * @param  array<string, float>  $citable
     * @return array{price: float, label: string}|null
     */
    public static function matchCitable(mixed $raw, array $citable): ?array
    {
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[^\d.,]/', '', (string) $raw));

        if ($clean === '') {
            return null;
        }

        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace(',', '', $clean);          // 1,234.50
        } elseif (str_contains($clean, ',')) {
            $clean = str_replace(',', '.', $clean);          // 305,00
        }

        $value = (float) $clean;

        if ($value <= 0) {
            return null;
        }

        $best = null;

        foreach ($citable as $label => $price) {
            $gap = abs($price - $value) / $price;

            if ($gap <= self::PRICE_MATCH_TOLERANCE && ($best === null || $gap < $best['gap'])) {
                $best = ['price' => $price, 'label' => $label, 'gap' => $gap];
            }
        }

        return $best === null ? null : ['price' => $best['price'], 'label' => $best['label']];
    }

    /** Plain text, one line, bounded; and empty when the trader holds nothing on that side. */
    public static function cleanNote(mixed $value, bool $applies, int $max = self::MAX_NOTE_LENGTH): string
    {
        if (! $applies || ! is_string($value)) {
            return '';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));

        return mb_substr($text, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $extras
     * @param  array<string, mixed>  $signal
     * @param  array<int, array<string, mixed>>  $positions
     * @param  array<string, mixed>  $risk
     * @param  array<string, float>  $citable
     */
    private function buildPrompt(string $symbol, string $language, array $extras, array $signal, array $positions, array $risk, array $citable): string
    {
        $context = [
            'symbol' => $symbol,
            'price'  => $extras['plan']['price'] ?? null,
            'signal' => $signal,
            'levels' => $signal['levels'] ?? null,
            'extras' => $extras,
        ];

        $lines = [
            'LANGUAGE: '.($language === 'sr' ? 'Serbian (Latin script)' : 'English'),
            '',
            ...$this->advisor->technicalContext($context),
            '',
            'THE TRADER\'S OPEN POSITIONS IN THIS COIN:',
            ...$this->positionLines($positions),
            '',
            ...$this->riskLines($risk),
            '',
            'PRICES YOU MAY CITE (use only these prices in the comments and in watch_price):',
        ];

        foreach ($citable as $label => $price) {
            $lines[] = "- {$label}: ".CandleReader::price($price);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $positions
     * @return array<int, string>
     */
    private function positionLines(array $positions): array
    {
        $lines = [];

        foreach ($positions as $p) {
            // The exchange reports 0 when it has no liquidation price for a leg.
            $liquidation = is_numeric($p['liquidation_price'] ?? null) && (float) $p['liquidation_price'] > 0
                ? (string) $p['liquidation_price']
                : 'none reported';

            $lines[] = "- {$this->v($p['direction'] ?? null)}: notional \${$this->v($p['notional'] ?? null)}, entry {$this->v($p['entry'] ?? null)}, unrealized PnL \${$this->v($p['pnl'] ?? null)}, leverage {$this->v($p['leverage'] ?? null)}x, liquidation price {$liquidation}; armed stop-loss {$this->v($p['stop_loss'] ?? null, 'none')}, take-profit {$this->v($p['take_profit'] ?? null, 'none')}";
            $lines[] = ! empty($p['locked'])
                ? '  LOCKED on purpose'.(! empty($p['locked_until']) ? " until {$this->v($p['locked_until'])}" : ' (indefinitely)').' — do not suggest touching it.'
                : '  Not locked.';
        }

        return $lines;
    }

    /**
     * The live risk figures the dashboard computed from the trader's positions: the whole
     * account's radar and this coin's net exposure, break-even and nearest liquidation.
     *
     * @param  array<string, mixed>  $risk
     * @return array<int, string>
     */
    private function riskLines(array $risk): array
    {
        $coin  = is_array($risk['coin'] ?? null) ? $risk['coin'] : [];
        $liq   = is_array($coin['liq'] ?? null) ? $coin['liq'] : null;
        $lines = ['RISK (computed live by the dashboard from the trader\'s positions):'];

        $lines[] = "- Whole account: risk radar {$this->v(isset($risk['radar_status']) ? strtoupper((string) $risk['radar_status']) : null)}; a typical hour moves about {$this->v($risk['typical_hour_pct'] ?? null)}% of equity (\${$this->v($risk['equity'] ?? null)}).";

        if ($coin !== []) {
            $net  = (float) ($coin['net_notional'] ?? 0);
            $side = $net > 0 ? 'LONG' : ($net < 0 ? 'SHORT' : 'flat');

            $line = "- This coin: net {$side} \$".number_format(abs($net), 0, '.', '')
                .(isset($coin['hedge_ratio']) ? ' (the smaller leg covers '.round((float) $coin['hedge_ratio'] * 100).'% of the larger one)' : '')
                .", combined PnL \${$this->v($coin['combined_pnl'] ?? null)}";

            if (isset($coin['break_even'])) {
                $line .= ", break-even {$this->v($coin['break_even'])}";
            }

            if (isset($coin['equity_zero'])) {
                $line .= ", equity would reach zero near {$this->v($coin['equity_zero'])} (an outer limit; the exchange liquidates sooner)";
            }

            $lines[] = $line.'.';
        }

        if ($liq !== null) {
            $lines[] = "- Nearest liquidation: {$this->v($liq['side'] ?? null)} at {$this->v($liq['price'] ?? null)}, {$this->v($liq['distance_pct'] ?? null)}% away"
                .(isset($liq['distance_atr']) ? " ({$this->v($liq['distance_atr'])} hourly ranges)" : '').'.';
        }

        return $lines;
    }

    private function v(mixed $value, string $fallback = 'n/a'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return is_scalar($value) ? (string) $value : $fallback;
    }
}
