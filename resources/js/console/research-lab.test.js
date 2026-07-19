import { describe, expect, it } from 'vitest';
import { renderEvidenceChart } from './research-charts';
import { holdoutDisclosure, renderResearchLab } from './research-lab';

describe('research lab', () => {
    it('never reveals a locked holdout result', () => {
        expect(holdoutDisclosure({ status: 'locked', values_revealed: false, result: { secret: 42 } })).toEqual({ status: 'locked', visibleResult: null, label: 'locked' });
    });

    it('renders explicit missing and incomplete chart states instead of zeros', () => {
        expect(renderEvidenceChart({ label: 'Equity', state: 'not_measured' })).toContain('Not measured');
        expect(renderEvidenceChart({ label: 'Equity', state: 'incomplete' })).toContain('Incomplete series');
        expect(renderEvidenceChart({ label: 'Equity', state: 'not_measured' })).not.toContain('polyline');
    });

    it('provides a non-color accessible summary for plotted evidence', () => {
        const chart = renderEvidenceChart({ label: 'Equity', state: 'measured', normal: [100, 105, 102], stressed: [100, 97, 99] });
        expect(chart).toContain('aria-label');
        expect(chart).toContain('changes by 2.00');
        expect(chart).toContain('Stressed · dashed');
    });

    it('shows evidence level before candidate performance and escapes names', () => {
        const html = renderResearchLab({ experiments: [{ id: 1, name: '<script>x</script>', status: 'running', objective: 'test', evidence_level: 'development', performance: {}, candidates: [], holdout: { status: 'locked' } }] });
        expect(html.indexOf('Evidence level')).toBeLessThan(html.indexOf('runs measured'));
        expect(html).not.toContain('<script>');
        expect(html).toContain('&lt;script&gt;');
    });
});
