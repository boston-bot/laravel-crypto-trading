import { measurementLabel } from './performance-formatters';
import { renderEvidenceChart } from './research-charts';
import { escapeMarkup } from './strategy-inspector';

export function holdoutDisclosure(holdout = {}) {
    const status = holdout.status || 'not_registered';
    const terminal = ['passed', 'failed', 'inconclusive'].includes(status);
    return {
        status,
        visibleResult: terminal && holdout.values_revealed ? holdout.result : null,
        label: status.replaceAll('_', ' '),
    };
}

export function metricValue(metrics = [], group, name, dimension = null) {
    const items = metrics?.[group] || [];
    const item = items.find(metric => metric.name === name && (dimension == null || metric.dimension === dimension));
    return item?.value ?? null;
}

function metric(label, value, suffix = '') {
    return `<div class="ops-lab-metric"><span>${escapeMarkup(label)}</span><strong>${value == null ? 'Not measured' : `${Number(value).toFixed(2)}${escapeMarkup(suffix)}`}</strong></div>`;
}

function statusTag(value, className = '') {
    return `<span class="ops-tag ${className}">${escapeMarkup(String(value || 'unknown').replaceAll('_', ' '))}</span>`;
}

function evidenceTable(title, items = []) {
    if (!items.length) return `<section class="ops-lab-table"><h4>${escapeMarkup(title)}</h4><p>Not measured</p></section>`;
    return `<section class="ops-lab-table"><h4>${escapeMarkup(title)}</h4><div class="ops-table-wrap"><table><thead><tr><th>Measure</th><th>Slice</th><th>Value</th></tr></thead><tbody>${items.map(item => `<tr><td>${escapeMarkup(item.name)}</td><td>${escapeMarkup(item.dimension || 'aggregate')}</td><td>${item.value == null ? 'Not measured' : escapeMarkup(Number(item.value).toFixed(3))}</td></tr>`).join('')}</tbody></table></div></section>`;
}

function lifecycle(holdout) {
    const stages = ['locked', 'authorized', 'running', 'passed', 'failed', 'inconclusive'];
    const disclosure = holdoutDisclosure(holdout);
    return `<div class="ops-holdout-life" aria-label="Holdout lifecycle. Current state ${escapeMarkup(disclosure.label)}">${stages.map(stage => `<span class="${stage === disclosure.status ? 'current' : ''}">${escapeMarkup(stage)}</span>`).join('')}</div><div class="ops-holdout-note"><strong>${escapeMarkup(disclosure.label)}</strong><p>${disclosure.visibleResult ? escapeMarkup(JSON.stringify(disclosure.visibleResult)) : 'Holdout values remain sealed. Authorization and evaluation are command-only operations.'}</p></div>`;
}

function candidateCard(candidate, evidenceLevel) {
    const run = candidate.runs?.[0];
    const performance = run?.performance || { measurement_state: 'not_measured' };
    const metrics = run?.metrics || {};
    const normal = run?.series?.normal_equity || [];
    const stressed = run?.series?.stressed_equity || [];
    const normalDrawdown = run?.series?.normal_drawdown || [];
    const stressedDrawdown = run?.series?.stressed_drawdown || [];
    const netReturn = metricValue(metrics, 'aggregate', 'net_return_pct') ?? metricValue(metrics, 'aggregate', 'total_return_pct');
    const rejections = candidate.rejection_reasons || [];
    return `<article class="ops-candidate-card ${candidate.role}">
        <header><div><span class="ops-section-label">${escapeMarkup(candidate.role)} · evidence ${escapeMarkup(evidenceLevel)}</span><h3>${escapeMarkup(candidate.family)}</h3><p>${escapeMarkup(candidate.key)}</p></div><div>${statusTag(candidate.status)}${statusTag(measurementLabel(performance), 'info')}</div></header>
        <div class="ops-lab-metrics">${metric('Net OOS return', netReturn, '%')}${metric('Max drawdown', metricValue(metrics, 'aggregate', 'max_drawdown_pct'), '%')}${metric('Turnover', metricValue(metrics, 'aggregate', 'turnover'))}${metric('Costs', metricValue(metrics, 'costs', 'fees_usd'), ' USD')}</div>
        <div class="ops-chart-pair">${renderEvidenceChart({ label: `${candidate.family} linked equity`, normal, stressed, state: performance.measurement_state })}${renderEvidenceChart({ label: `${candidate.family} drawdown`, normal: normalDrawdown, stressed: stressedDrawdown, state: performance.measurement_state })}</div>
        <div class="ops-research-evidence-grid">${evidenceTable('Outer folds', metrics.folds)}${evidenceTable('Robustness neighborhood', metrics.robustness)}${evidenceTable('Asset attribution', metrics.attribution?.asset)}${evidenceTable('Regime attribution', metrics.attribution?.regime)}${evidenceTable('Holding periods / exits', [...(metrics.attribution?.holding_period || []), ...(metrics.attribution?.exit || [])])}${evidenceTable('Benchmarks and costs', [...(metrics.benchmarks || []), ...(metrics.costs || [])])}</div>
        <div class="ops-rejection-box"><span>Gate resolution</span>${rejections.length ? `<ul>${rejections.map(reason => `<li>${escapeMarkup(reason)}</li>`).join('')}</ul>` : `<p>${run?.gate?.status === 'passed' ? 'All recorded development gates passed.' : 'No rejection reason has been measured yet.'}</p>`}</div>
    </article>`;
}

export function renderResearchLab(payload = {}, selected = null) {
    const experiments = payload.experiments || [];
    const experiment = selected || experiments[0];
    if (!experiment) return `<section class="ops-lab-shell"><header><div><span class="ops-section-label">Candidate cockpit</span><h2>No preregistered experiment</h2><p>Create an experiment to compare the four bounded strategy families.</p></div></header></section>`;
    return `<section class="ops-lab-shell">
        <header><div><span class="ops-section-label">Champion / challenger research</span><h2>${escapeMarkup(experiment.name)}</h2><p>Evidence level <strong>${escapeMarkup(experiment.evidence_level)}</strong> appears before performance. ${escapeMarkup(experiment.objective)}</p></div><label>Experiment<select name="research_experiment" data-action="experiment-selection">${experiments.map(item => `<option value="${Number(item.id)}" ${item.id === experiment.id ? 'selected' : ''}>${escapeMarkup(item.name)}</option>`).join('')}</select></label></header>
        <div class="ops-lab-banner"><div>${statusTag(experiment.status)}${statusTag(experiment.performance?.measurement_state || 'not_measured', 'info')}</div><span>${experiment.performance?.measured_runs || 0}/${experiment.performance?.total_runs || 0} runs measured · costs included</span><span>${escapeMarkup(experiment.universe?.symbols?.join(' · ') || 'Universe not recorded')}</span></div>
        <section class="ops-holdout-panel"><header><span class="ops-section-label">Globally single-use interval</span><h3>Holdout remains outside HTTP control</h3></header>${lifecycle(experiment.holdout)}</section>
        <div class="ops-candidate-stack">${(experiment.candidates || []).map(candidate => candidateCard(candidate, experiment.evidence_level)).join('')}</div>
    </section>`;
}
