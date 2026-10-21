<?php

namespace Tests\Unit\PaperTrading;

use App\Services\PaperTrading\Experiments\ExperimentDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ExperimentFixture as F;
use Tests\TestCase;

class ExperimentDefinitionTest extends TestCase
{
    public function test_specification_rejects_execution_permissions_unbounded_or_untyped_variants_and_unsupported_fields(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21T14:00:00Z'));
        foreach (['permission', 'float_cash', 'float_parameter', 'risk_override', 'symbols', 'dates', 'baseline', 'duplicates'] as $case) {
            $spec = F::spec();
            match ($case) {
                'permission' => $spec['brokerage_writes_enabled'] = true,
                'float_cash' => $spec['variants'][0]['equity'] = 500.0,
                'float_parameter' => $spec['variants'][0]['parameters'] = ['initial_stop_atr' => 2.0],
                'risk_override' => $spec['variants'][0]['parameters'] = ['risk_per_trade_pct' => '50'],
                'symbols' => $spec['symbols'] = ['A', 'B', 'C'],
                'dates' => $spec['sessions'] = array_reverse($spec['sessions']),
                'baseline' => $spec['baseline'] = 'absent',
                'duplicates' => $spec['variants'][1]['equity'] = '500',
            };
            try {
                app(ExperimentDefinition::class)->validate($spec);
                $this->fail('Invalid specification accepted: '.$case);
            } catch (ValidationException|HttpException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_canonical_fingerprints_ignore_object_key_order_but_preserve_list_order_and_types(): void
    {
        $this->assertSame(ExperimentDefinition::hash(['b' => 1, 'a' => ['d' => '2', 'c' => 3]]), ExperimentDefinition::hash(['a' => ['c' => 3, 'd' => '2'], 'b' => 1]));
        $this->assertNotSame(ExperimentDefinition::hash(['1', '2']), ExperimentDefinition::hash(['2', '1']));
        $this->assertNotSame(ExperimentDefinition::hash('500'), ExperimentDefinition::hash(500));
    }
}
