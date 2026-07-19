import { describe, expect, it } from 'vitest';
import { measurementLabel } from './performance-formatters';
import { escapeMarkup, groupBlockers, renderCounterfactual, renderStrategyInspector } from './strategy-inspector';

describe('strategy inspector', () => {
    it('labels every measurement state explicitly', () => {
        expect(measurementLabel()).toBe('Not measured');
        expect(measurementLabel({ measurement_state: 'incomplete' })).toBe('Incomplete');
        expect(measurementLabel({ measurement_state: 'measured', stale: true })).toBe('Stale');
        expect(measurementLabel({ measurement_state: 'measured', stale: false })).toBe('Measured');
    });

    it('groups blockers by operator-meaningful category', () => {
        expect(groupBlockers(['missing_manifest', 'drawdown_ceiling', 'insufficient_net_edge'])).toEqual({
            evidence: ['missing_manifest'],
            strategy: ['insufficient_net_edge'],
            risk: ['drawdown_ceiling'],
        });
    });

    it('renders a counterfactual or an explicit not-measured state', () => {
        expect(renderCounterfactual('Enter above 12 bps.')).toContain('Enter above 12 bps.');
        expect(renderCounterfactual(null)).toContain('Not measured');
    });

    it('escapes unsafe text throughout the rendered decision', () => {
        const unsafe = '<img src=x onerror=alert(1)>';
        expect(escapeMarkup(unsafe)).not.toContain('<img');
        const html = renderStrategyInspector({ decision: { asset: { symbol: unsafe }, immediate: { action: 'HOLD', explanation: unsafe }, mechanics: {}, audit: {}, performance: { measurement_state: 'measured' } } });
        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;img');
    });
});
