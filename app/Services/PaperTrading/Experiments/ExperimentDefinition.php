<?php

declare(strict_types=1);

namespace App\Services\PaperTrading\Experiments;

use App\Models\McpConnection;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\ProfileCalculator;
use App\Services\PaperTrading\ResearchVariants;
use App\Services\PaperTrading\SignalExecutionPolicies;
use App\Services\PaperTrading\SignalParameters;
use App\Services\PaperTrading\SignalRules;
use App\Services\PaperTrading\TradingCosts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class ExperimentDefinition
{
    public const VERSION = 'strategy_experiment_v1';

    public const DATA_CONTRACT = 'historical_sip_raw_regular_5m_opening30s_full_sessions_v2';

    private static ?array $processSources = null;

    public function __construct()
    {
        // Long-lived workers must restart before processing definitions created from changed PHP files.
        self::$processSources ??= $this->sources();
    }

    public function compatible(array $hashes): bool
    {
        return $hashes === self::$processSources && $hashes === $this->sources();
    }

    public static function hash(mixed $value): string
    {
        $canonical = function ($item) use (&$canonical) {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($canonical, $item);
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }

    public function validate(array $spec): array
    {
        Validator::make(['spec' => $spec], [
            'spec' => ['required', 'array:name,hypothesis,symbols,sessions,baseline,variants,dataset_experiment'],
            'spec.name' => ['required', 'string', 'max:120'], 'spec.hypothesis' => ['required', 'string', 'max:2000'],
            'spec.symbols' => ['required', 'array', 'min:1', 'max:2'], 'spec.symbols.*' => ['required', 'string', 'distinct', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'],
            'spec.sessions' => ['required', 'array', 'min:2', 'max:20'], 'spec.sessions.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'spec.baseline' => ['required', 'string'], 'spec.dataset_experiment' => ['sometimes', 'uuid'],
            'spec.variants' => ['required', 'array', 'min:1', 'max:8'],
            'spec.variants.*' => ['required', 'array:id,equity,parameters'],
            'spec.variants.*.id' => ['required', 'string', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,31}$/D'],
            'spec.variants.*.equity' => ['required', 'string', 'regex:/^[1-9]\d{0,8}(?:\.\d{1,2})?$/D'],
            'spec.variants.*.parameters' => ['sometimes', 'array:'.implode(',', ResearchVariants::parameterKeys())],
        ])->validate();
        abort_unless(array_is_list($spec['symbols']) && array_is_list($spec['sessions']) && array_is_list($spec['variants']), 422, 'Use JSON lists.');
        $ordered = $spec['sessions'];
        sort($ordered);
        abort_unless($ordered === $spec['sessions'] && in_array($spec['baseline'], array_column($spec['variants'], 'id'), true), 422, 'Use ascending sessions and an existing baseline.');
        foreach ($spec['sessions'] as $date) {
            abort_unless($date <= CarbonImmutable::now('America/New_York')->toDateString() && TradingCosts::valid(TradingCosts::reviewed(), CarbonImmutable::parse($date, 'America/New_York')), 422, 'Sessions must be historical and covered by reviewed costs.');
        }
        $seen = [];
        foreach ($spec['variants'] as &$variant) {
            $variant['parameters'] = ResearchVariants::parameters($variant['parameters'] ?? []);
            $key = self::hash([$variant['equity'], $variant['parameters']]);
            abort_if(isset($seen[$key]), 422, 'Duplicate variant configuration.');
            $seen[$key] = true;
        }
        unset($variant);
        sort($spec['symbols']);

        return $spec;
    }

    public function freeze(McpConnection $connection, array $spec): array
    {
        $sources = $this->sources();
        abort_unless($this->compatible($sources), 409, 'Restart the experiment worker after a source change.');
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->firstOrFail();
        $policy = PolicyApplication::where('paper_portfolio_id', $portfolio->id)->where('revision', $portfolio->policy_revision)->firstOrFail();
        $signalPolicy = SignalExecutionPolicies::current($portfolio);
        $settings = ($signalPolicy?->payload ?? SignalExecutionPolicies::DEFAULTS) + ['cost_schedule' => TradingCosts::reviewed()];
        $settings += array_intersect_key($policy->snapshot['rules'], array_flip(['cash_pct', 'active_pct', 'daily_loss_pause_pct']));
        $parameters = SignalParameters::current();
        $variants = [];
        foreach ($spec['variants'] as $variant) {
            $values = array_replace($parameters['values'], $variant['parameters']);
            $variants[] = $variant + ['values' => $values, 'parameters_hash' => self::hash($values)];
        }

        return ['version' => self::VERSION, 'data_contract' => self::DATA_CONTRACT, 'spec' => $spec, 'variants' => $variants, 'settings' => $settings,
            'parameters' => $parameters, 'source_hashes' => $sources, 'profile_version' => ProfileCalculator::VERSION, 'rule_version' => SignalRules::VERSION,
            'auth_generation' => $connection->auth_generation, 'policy_application_id' => $policy->id, 'signal_policy_id' => $signalPolicy?->id,
            'evaluation_role' => 'exploratory', 'broker_executable' => false, 'forward_ledger_changed' => false];
    }

    public function sources(): array
    {
        $paths = ['app/Services/Broker/Agentic/RobinhoodOrderContract.php', 'composer.lock', 'app/Jobs/RunStrategyExperiment.php', 'app/Models/PaperTrading/StrategyExperiment.php', 'app/Models/PaperTrading/StrategyExperimentStep.php',
            'app/Support/Mcp/Decimal.php', 'app/Support/Mcp/ExactJsonNumbers.php'];
        foreach (['StrategyReplay', 'ResearchVariants', 'IntradayQuoteReplay', 'MultiSessionQuoteReplay', 'IntradayReplayInputs', 'ProfileCalculator', 'SignalRules', 'SignalParameters',
            'StrategyParameters', 'RiskSizer', 'PaperFillPrice', 'SignalOrderPrice', 'TradingCosts', 'EligibilityRules', 'RuleEligibilityPolicies', 'PaperMarketReferences', 'SignalExecutionPolicies'] as $name) {
            $paths[] = 'app/Services/PaperTrading/'.$name.'.php';
        }
        foreach (['HistoricalQuotes', 'DailyBars', 'TradingCalendar', 'CorporateActions'] as $name) {
            $paths[] = 'app/Services/MarketData/Alpaca/'.$name.'.php';
        }
        foreach (glob(__DIR__.'/*.php') as $path) {
            $paths[] = 'app/Services/PaperTrading/Experiments/'.basename($path);
        }
        sort($paths);
        $hashes = [];
        foreach ($paths as $path) {
            $hashes[$path] = hash_file('sha256', base_path($path));
        }

        return $hashes;
    }
}
