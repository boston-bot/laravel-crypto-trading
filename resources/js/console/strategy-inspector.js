import { formatBps, formatProbability, measurementLabel } from './performance-formatters';

export function escapeMarkup(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

export function groupBlockers(blockers = []) {
    const groups = { evidence: [], strategy: [], risk: [], policy: [], operations: [] };
    blockers.forEach(blocker => {
        const value = String(blocker);
        if (/evidence|manifest|point_in_time|schema|version/i.test(value)) groups.evidence.push(value);
        else if (/risk|drawdown|capacity|cash|position/i.test(value)) groups.risk.push(value);
        else if (/policy|approval|expired|stale/i.test(value)) groups.policy.push(value);
        else if (/engine|output|reconciliation|missing/i.test(value)) groups.operations.push(value);
        else groups.strategy.push(value);
    });
    return Object.fromEntries(Object.entries(groups).filter(([, values]) => values.length));
}

export function renderCounterfactual(value) {
    return value
        ? `<div class="ops-counterfactual"><span>What would change this decision?</span><p>${escapeMarkup(value)}</p></div>`
        : '<div class="ops-counterfactual muted"><span>Counterfactual</span><p>Not measured for this evaluation.</p></div>';
}

function tag(value, className = '') {
    return `<span class="ops-tag ${className}">${escapeMarkup(String(value || 'unknown').replaceAll('_', ' '))}</span>`;
}

function metric(label, value, detail = '') {
    return `<div class="ops-inspector-metric"><span>${escapeMarkup(label)}</span><strong>${escapeMarkup(value ?? '—')}</strong>${detail ? `<small>${escapeMarkup(detail)}</small>` : ''}</div>`;
}

function rulesTable(rules = []) {
    if (!rules.length) return '<p class="ops-inline-empty">No rule checklist was captured.</p>';
    return `<div class="ops-rule-list">${rules.map(rule => `<div class="ops-rule ${rule.passed ? 'passed' : 'failed'}"><span aria-hidden="true">${rule.passed ? '✓' : '×'}</span><strong>${escapeMarkup(rule.rule || rule.name || 'Rule')}</strong><small>${escapeMarkup(rule.observed ?? '—')} / ${escapeMarkup(rule.required ?? '—')}</small></div>`).join('')}</div>`;
}

function factorRows(factors = {}) {
    const entries = Object.entries(factors);
    if (!entries.length) return '<p class="ops-inline-empty">No factor attribution was measured.</p>';
    const max = Math.max(...entries.map(([, value]) => Math.abs(Number(value) || 0)), 1);
    return `<div class="ops-factor-list">${entries.map(([name, value]) => `<div><span>${escapeMarkup(name.replaceAll('_', ' '))}</span><div class="ops-factor-track"><i class="${Number(value) < 0 ? 'negative' : ''}" style="--factor-width:${Math.min(100, Math.abs(Number(value) || 0) / max * 100)}%"></i></div><strong>${escapeMarkup(formatBps(value))}</strong></div>`).join('')}</div>`;
}

export function renderStrategyInspector(payload = {}) {
    const decision = payload.decision;
    const selection = payload.selection || {};
    const assets = selection.assets || [];
    const cycles = selection.cycles || [];
    const selectedAsset = decision?.asset?.id ?? selection.asset_id ?? '';
    const selectedCycle = decision?.cycle_id ?? selection.cycle_id ?? '';
    const selectors = `<div class="ops-inspector-controls"><label>Asset<select data-action="decision-selection" name="decision_asset"><option value="">Latest across universe</option>${assets.map(asset => `<option value="${Number(asset.id)}" ${Number(selectedAsset) === Number(asset.id) ? 'selected' : ''}>${escapeMarkup(asset.symbol)}</option>`).join('')}</select></label><label>Logical bar<select data-action="decision-selection" name="decision_cycle"><option value="">Latest available</option>${cycles.map(cycle => `<option value="${escapeMarkup(cycle.id)}" ${String(selectedCycle) === String(cycle.id) ? 'selected' : ''}>${escapeMarkup(cycle.logical_bar_close || cycle.id)}</option>`).join('')}</select></label></div>`;
    if (!decision) return `<section class="ops-decision-shell"><header><div><span class="ops-section-label">Decision inspector</span><h2>Nothing has been evaluated yet</h2><p>Run a canonical paper cycle to record the first strategy decision.</p></div>${selectors}</header></section>`;

    const immediate = decision.immediate || {};
    const mechanics = decision.mechanics || {};
    const audit = decision.audit || {};
    const blockers = groupBlockers(mechanics.blockers || []);
    const actionClass = String(immediate.action || '').toLowerCase();
    const measurement = measurementLabel(decision.performance);

    return `<section class="ops-decision-shell">
        <header><div><span class="ops-section-label">Latest canonical decision</span><h2>${escapeMarkup(decision.asset?.symbol || 'Asset')} <em class="ops-action-word ${actionClass}">${escapeMarkup(immediate.action || 'HOLD')}</em></h2><p>${escapeMarkup(immediate.explanation || 'No explanation was recorded.')}</p></div>${selectors}</header>
        <div class="ops-decision-facts"><div>${tag(immediate.resolution, actionClass === 'hold' ? 'hold' : '')}${tag(measurement, measurement === 'Measured' ? 'success' : 'info')}</div><span>${escapeMarkup(immediate.strategy_family || 'Unknown family')} · ${escapeMarkup(immediate.strategy_definition_version || 'unversioned')}</span><span>${immediate.eligible ? 'Eligible' : 'Not eligible'} · ${immediate.actionable ? 'Actionable' : 'No action dispatched'}</span><span>${escapeMarkup(decision.logical_bar_close || 'No logical bar')}</span></div>
        <div class="ops-inspector-grid">
            ${metric('Rank', mechanics.rank, 'Cross-sectional order')}
            ${metric('Score', mechanics.score == null ? '—' : Number(mechanics.score).toFixed(3), 'Canonical score')}
            ${metric('Probability', formatProbability(mechanics.calibrated_probability), 'Calibrated')}
            ${metric('Gross edge', formatBps(mechanics.gross_edge_bps))}
            ${metric('Estimated costs', formatBps(mechanics.cost_estimate_bps))}
            ${metric('Net edge', formatBps(mechanics.net_edge_bps), mechanics.regime ? `Regime · ${mechanics.regime}` : '')}
        </div>
        <div class="ops-inspector-columns">
            <article class="ops-inspector-layer"><header><span>Layer 02</span><h3>Why the rulebook resolved here</h3></header>${rulesTable(mechanics.rules)}${renderCounterfactual(mechanics.counterfactual)}${Object.keys(blockers).length ? `<div class="ops-blocker-groups">${Object.entries(blockers).map(([group, values]) => `<div><span>${escapeMarkup(group)}</span><p>${values.map(escapeMarkup).join(' · ')}</p></div>`).join('')}</div>` : '<p class="ops-inline-empty">No blockers were recorded.</p>'}</article>
            <article class="ops-inspector-layer"><header><span>Layer 03</span><h3>Evidence and immutable lineage</h3></header>${factorRows(audit.factor_contributions)}<details><summary>Thresholds and portfolio state</summary><pre>${escapeMarkup(JSON.stringify({ thresholds: audit.thresholds || {}, portfolio_state: audit.portfolio_state || {} }, null, 2))}</pre></details><details><summary>Risk, policy, execution, and accounting</summary><pre>${escapeMarkup(JSON.stringify({ decision: audit.decision || null, execution: audit.execution || null }, null, 2))}</pre></details><div class="ops-lineage"><span>Trace ${escapeMarkup(audit.lineage?.decision_trace_hash?.slice(0, 12) || 'not recorded')}</span><span>Evidence ${escapeMarkup(audit.lineage?.evidence_hash?.slice(0, 12) || 'not recorded')}</span></div></article>
        </div>
    </section>`;
}
