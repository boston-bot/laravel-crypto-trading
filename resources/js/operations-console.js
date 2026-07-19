import './bootstrap';
import { buildActionPayload } from './idempotency-key';

const body = document.body;
const page = body.dataset.consolePage || 'overview';
const content = document.getElementById('consoleContent');
const alertBox = document.getElementById('consoleAlert');
const refreshState = document.getElementById('refreshState');
const lastUpdated = document.getElementById('lastUpdated');
const toast = document.getElementById('opsToast');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const endpoint = `/api/ops/v1/${page === 'overview' ? 'overview' : page}`;

const state = { timer: null, controller: null, lastGood: null, failures: 0, windowDays: 30 };

document.addEventListener('DOMContentLoaded', () => {
    content.addEventListener('click', handleClick);
    content.addEventListener('change', handleChange);
    content.addEventListener('submit', handleSubmit);
    document.addEventListener('visibilitychange', schedule);
    refresh();
});

async function refresh() {
    state.controller?.abort();
    state.controller = new AbortController();
    setRefreshState('Refreshing', 'working');
    try {
        const response = await fetch(`${endpoint}?window_days=${state.windowDays}`, { signal: state.controller.signal, headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`Status ${response.status}`);
        const payload = await response.json();
        state.lastGood = payload.data;
        state.failures = 0;
        render(payload.data);
        alertBox.hidden = true;
        const now = new Date();
        lastUpdated.textContent = `Updated ${formatTime(now.toISOString())}`;
        setRefreshState(page === 'operations' && !allHealthy(payload.data) ? 'Attention needed' : 'Current', allHealthy(payload.data) ? 'healthy' : 'partial');
    } catch (error) {
        if (error.name === 'AbortError') return;
        state.failures += 1;
        alertBox.hidden = false;
        alertBox.textContent = state.lastGood ? 'Live refresh failed. Showing the last successful data; retrying automatically.' : 'The console could not load. Confirm the Laravel app and database are running.';
        setRefreshState('Stale', 'failed');
    } finally {
        schedule();
    }
}

function schedule() {
    clearTimeout(state.timer);
    const base = document.hidden ? 60000 : 10000;
    const delay = Math.min(120000, base * Math.max(1, 2 ** Math.min(state.failures, 3)));
    state.timer = setTimeout(refresh, delay);
}

function render(data) {
    content.className = 'ops-content';
    content.setAttribute('aria-busy', 'false');
    const renderers = { overview: renderOverview, strategies: renderStrategies, assets: renderAssets, activity: renderActivity, paper: renderPaper, operations: renderOperations, research: renderResearch };
    content.innerHTML = (renderers[page] || renderOverview)(data);
}

function renderOverview(data) {
    const cycle = data.latest_cycle;
    const session = data.paper?.session;
    const snapshot = data.paper?.snapshot;
    const evaluations = cycle?.evaluations || [];
    const happened = data.activity?.length ? data.activity : cycleEvents(cycle);
    return `
        <section class="ops-hero-grid">
            <article class="ops-story">
                <div class="ops-section-label">Latest cycle · ${cycle ? formatTime(cycle.created_at) : 'Not started'}</div>
                <h2>${cycleHeadline(cycle, session)}</h2>
                <p>${escapeHtml(data.latest_explanation)}</p>
                ${primaryReason(evaluations)}
                <div class="ops-actions">
                    ${data.account ? (session ? `<button class="ops-button primary" data-action="cycle" data-account="${data.account.id}">Run paper cycle</button><button class="ops-button" data-action="sync">Sync data</button><button class="ops-button" data-action="diagnostic" data-account="${data.account.id}">Evaluate now</button>` : '<a class="ops-button primary" href="/paper">Start paper session</a><button class="ops-button" data-action="sync">Sync data</button>') : '<a class="ops-button primary" href="/operations">Set up account sync</a>'}
                </div>
            </article>
            <article class="ops-summary-card"><span>Strategy · paper</span><strong>${formatMeasuredPerformance(data.paper?.portfolio_summary?.strategy_return_pct, data.paper?.performance)}</strong><p>${performanceDescription(data.paper?.performance, session ? `Session #${session.id} · ${session.funding_mode} capital` : 'No active paper session')}</p><a href="/paper">Open paper portfolio →</a></article>
            <article class="ops-summary-card"><span>System</span><strong class="${data.system?.healthy ? 'positive' : 'warning'}">${data.system?.healthy ? 'Healthy' : 'Needs attention'}</strong><p>${data.system?.processes?.filter(p => p.display_state === 'running').length || 0} of ${data.system?.processes?.length || 4} processes online · ${data.system?.engine_pending || 0} engine jobs pending</p><a href="/operations">Open operations →</a></article>
        </section>
        <section class="ops-two-column">
            <article class="ops-panel"><header><div><span class="ops-section-label">Evidence trail</span><h3>What happened</h3></div><a href="/activity">View all activity →</a></header>${happened.length ? renderEventList(happened) : (session ? emptyState('No activity yet', 'Run a paper cycle to record data, evaluation, and outcome events.', 'Run paper cycle', 'cycle', data.account?.id) : emptyState('No paper session yet', 'Start a funded paper session before asking the strategy to evaluate trades.', 'Start paper session', 'link', '/paper'))}</article>
            <article class="ops-panel"><header><div><span class="ops-section-label">Selected window</span><h3>Asset contribution</h3></div><a href="/assets">All assets →</a></header>${renderAssetRows(data.assets || [])}</article>
        </section>
        ${!snapshot && session ? emptyState('No paper snapshot yet', 'Run a paper cycle to create the first portfolio valuation.', 'Run paper cycle', 'cycle', data.account?.id) : ''}`;
}

function renderStrategies(data) {
    const versions = data.versions || [];
    const tests = data.backtests || [];
    const runs = data.latest_runs || [];
    return `<section class="ops-feature-strip"><div><span class="ops-section-label">Active strategy</span><h2>${escapeHtml(data.active_name)}</h2><p>Closed 4-hour bars · spot long/flat · five-asset universe</p></div><div class="ops-feature-stat"><span>30d strategy return</span><strong>${formatMeasuredPerformance(data.portfolio_summary?.strategy_return_pct, data.performance)}</strong></div><div class="ops-feature-stat"><span>Evidence state</span><strong>${performanceStateLabel(data.performance)}</strong></div></section>
        <section class="ops-two-column"><article class="ops-panel"><header><div><span class="ops-section-label">Immutable evidence</span><h3>Strategy versions</h3></div></header>${versions.length ? renderTable(['Version', 'State', 'Engine', 'Activated'], versions.map(v => [v.version, statusTag(v.status), v.engine_version, formatTime(v.activated_at)])) : emptyState('No immutable strategy version yet', 'The legacy strategy is active, but it has not been frozen as a version.', null)}</article>
        <article class="ops-panel"><header><div><span class="ops-section-label">Recent execution</span><h3>Strategy runs</h3></div></header>${runs.length ? renderTable(['Started', 'Mode', 'State'], runs.map(r => [formatTime(r.started_at), r.mode, statusTag(r.status)])) : emptyState('No strategy runs yet', 'Run a paper cycle from Overview.', 'Run from overview', 'link', '/dashboard')}</article></section>
        <section class="ops-panel"><header><div><span class="ops-section-label">Historical replay</span><h3>Backtest runs</h3></div></header>${tests.length ? renderTable(['Started', 'Window', 'Status', 'Sharpe', 'Drawdown'], tests.map(r => [formatTime(r.run_started_at), `${shortDate(r.timeframe_start)} → ${shortDate(r.timeframe_end)}`, statusTag(r.status), metric(r.metrics, 'sharpe_ratio'), `${metric(r.metrics, 'max_drawdown_pct')}%`])) : emptyState('No completed backtests', 'Queue a backtest to populate fold and calibration evidence.', null)}</section>`;
}

function renderAssets(data) {
    const assets = data.assets || [];
    return `<div class="ops-filter-row"><span>Strategy attribution beside raw Coinbase price return</span><label>Window <select id="windowDays" data-action="window"><option value="7" ${data.window_days === 7 ? 'selected' : ''}>7 days</option><option value="30" ${data.window_days === 30 ? 'selected' : ''}>30 days</option><option value="90" ${data.window_days === 90 ? 'selected' : ''}>90 days</option></select></label></div>
        <section class="ops-asset-grid">${assets.map(asset => `<article class="ops-asset-card"><header><div><span class="ops-coin">${escapeHtml(asset.symbol.slice(0, 1))}</span><div><h2>${escapeHtml(asset.symbol)}</h2><p>${asset.position ? `${formatNumber(asset.position.quantity, 6)} held` : 'No open paper position'}</p></div></div>${statusTag(asset.latest_evaluation?.action || 'NOT EVALUATED')}</header><div class="ops-asset-comparison"><div><span>P&amp;L contribution</span><strong class="${tone(asset.pnl_contribution_pct)}">${formatMeasuredPerformance(asset.pnl_contribution_pct, data.performance)}</strong><small>${asset.net_pnl == null ? performanceStateLabel(data.performance) : `${formatMoney(asset.net_pnl)} attributed net P&amp;L`}</small></div><div><span>Held-period linked return</span><strong>${formatMeasuredPerformance(asset.held_period_linked_return_pct, data.performance)}</strong><small>Completed attributed holdings</small></div><div><span>Buy &amp; hold price return</span><strong>${formatPct(asset.buy_and_hold_price_return_pct)}</strong><small>${asset.benchmark_available ? `${data.window_days}d Coinbase closes · costs excluded` : 'Boundary prices unavailable'}</small></div></div><div class="ops-evaluation-note"><span>Latest evaluation</span><p>${escapeHtml(asset.latest_evaluation?.primary_explanation || 'No evaluation has been recorded for this asset.')}</p></div></article>`).join('')}</section>`;
}

function renderActivity(data) {
    const events = data.events || [];
    return `<section class="ops-panel ops-activity-panel"><header><div><span class="ops-section-label">Append-only audit</span><h3>Evaluation to outcome</h3></div><span>${events.length} recent events</span></header>${events.length ? renderEventList(events) : emptyState('No activity recorded', 'Start a paper session and run a cycle. Data sync, evaluations, holds, proposals, and fills will appear here.', 'Open paper setup', 'link', '/paper')}</section>`;
}

function renderPaper(data) {
    if (!data.account) return emptyState('No Coinbase account snapshot', 'Run account sync before creating a paper session.', 'Open operations', 'link', '/operations');
    if (!data.session) {
        const finalists = data.research_finalists || [];
        const strategyOptions = finalists.map(f => `<option value="${Number(f.strategy_version_id)}">${escapeHtml(`${f.strategy_name} · ${f.strategy_version} · ${f.family}`)}</option>`).join('');
        const universeOptions = finalists.map(f => `<option value="${Number(f.universe_version_id)}">${escapeHtml(`Universe ${f.universe_version}`)}</option>`).join('');
        const evidenceFields = finalists.length ? `<label class="ops-check"><input name="evidence_eligible" type="checkbox" value="1">Collect promotion evidence</label><label>Frozen strategy<select name="strategy_version_id">${strategyOptions}</select></label><label>Frozen universe<select name="universe_version_id">${universeOptions}</select></label>` : '<p class="ops-form-note">No holdout-passing finalist is available. This session will be operational paper only.</p>';
        return `<section class="ops-paper-onboarding"><div><span class="ops-section-label">Create a paper session</span><h2>Give the strategy capital it can actually account for.</h2><p>Virtual mode starts with an independent balance. Research evidence is accepted only when an exact holdout-passing strategy and universe are pinned.</p></div><form id="paperSessionForm" class="ops-session-form"><input type="hidden" name="broker_account_id" value="${data.account.id}"><label>Funding mode<select name="funding_mode" id="fundingMode"><option value="virtual">Virtual capital</option><option value="mirror">Mirror Coinbase equity (${formatMoney(data.account.equity)})</option></select></label><label>Virtual starting capital<input name="virtual_capital" type="number" min="1" step="0.01" value="10000"></label>${evidenceFields}<button class="ops-button primary" type="submit">Start paper session</button></form></section>${renderSessionHistory(data.history || [])}`;
    }
    const s = data.session;
    return `<section class="ops-feature-strip"><div><span class="ops-section-label">Active session #${s.id}</span><h2>${formatMoney(data.snapshot?.equity ?? s.opening_cash)}</h2><p>${s.funding_mode} capital · started ${shortDate(s.started_at)} · ${escapeHtml(s.fee_scenario)}</p>${s.evidence_eligible ? statusTag(data.paper_evidence?.status || s.evidence_status) : '<span class="ops-tag">Operational paper</span>'}</div><div class="ops-feature-stat"><span>Available cash</span><strong>${formatMoney(data.available_cash)}</strong></div><div class="ops-feature-stat"><span>Drawdown</span><strong>${formatPct(data.snapshot?.drawdown_pct)}</strong></div><button class="ops-button danger" data-action="end-session" data-session="${s.id}">End session</button></section>
        <section class="ops-two-column"><article class="ops-panel"><header><div><span class="ops-section-label">Current exposure</span><h3>Open positions</h3></div><span>${data.positions?.length || 0} of ${2} allowed</span></header>${data.positions?.length ? renderTable(['Asset', 'Quantity', 'Market value', 'Unrealized'], data.positions.map(p => [p.asset?.symbol, formatNumber(p.quantity, 8), formatMoney(p.market_value), `<span class="${tone(p.unrealized_pnl)}">${formatMoney(p.unrealized_pnl)}</span>`])) : emptyState('No open positions', 'The session is funded. The next eligible cycle may still HOLD if entry conditions are not met.', 'Run paper cycle', 'cycle', data.account.id)}</article>
        <article class="ops-panel"><header><div><span class="ops-section-label">Human review</span><h3>Pending proposals</h3></div><span>${data.pending_proposals?.length || 0}</span></header>${data.pending_proposals?.length ? data.pending_proposals.map(proposalCard).join('') : emptyState('No proposals need review', 'This is different from no activity: open Activity to see the latest HOLD reasons.', 'View activity', 'link', '/activity')}</article></section>
        <section class="ops-panel"><header><div><span class="ops-section-label">Cash accounting</span><h3>Ledger</h3></div><button class="ops-button" data-action="cycle" data-account="${data.account.id}">Run paper cycle</button></header>${renderTable(['Time', 'Type', 'Asset', 'Cash', 'Fee'], (data.ledger || []).map(e => [formatTime(e.occurred_at), e.entry_type.replaceAll('_', ' '), e.asset?.symbol || 'USD', `<span class="${tone(e.cash_delta)}">${formatMoney(e.cash_delta)}</span>`, formatMoney(e.fee)]))}</section>`;
}

function renderOperations(data) {
    const processes = data.processes || [];
    const cycles = data.cycles || [];
    return `<section class="ops-runtime-hero"><div><span class="ops-section-label">Control agent</span><h2>${processes.some(p => p.display_state !== 'offline') ? 'Runtime observed' : 'Runtime offline'}</h2><p>${processes.some(p => p.display_state !== 'offline') ? 'Lifecycle requests are fulfilled by the local control agent.' : `Start it in a terminal: ${escapeHtml(data.bootstrap_command)}`}</p></div><div class="ops-actions"><button class="ops-button primary" data-action="sync">Sync market data</button>${data.account && data.paper_session ? `<button class="ops-button" data-action="cycle" data-account="${data.account.id}">Run full cycle</button>` : (data.account ? '<a class="ops-button" href="/paper">Start paper session</a>' : '')}</div></section>
        <section class="ops-two-column"><article class="ops-panel"><header><div><span class="ops-section-label">Managed workloads</span><h3>Processes</h3></div><span>${processes.filter(p => p.display_state === 'running').length}/${processes.length} online</span></header>${processes.map(processRow).join('')}</article>
        <article class="ops-panel"><header><div><span class="ops-section-label">Laravel queue</span><h3>Work state</h3></div></header><div class="ops-number-pair"><div><span>Pending</span><strong>${data.queue?.pending || 0}</strong></div><div><span>Failed</span><strong class="${data.queue?.failed ? 'negative' : ''}">${data.queue?.failed || 0}</strong></div></div><div class="ops-callout"><strong>Python seam</strong><p>${data.engine_jobs?.filter(j => ['pending', 'leased'].includes(j.status)).length || 0} engine jobs pending or leased. Python has database-only access and no broker credentials.</p></div></article></section>
        <section class="ops-panel"><header><div><span class="ops-section-label">Minute heartbeat</span><h3>Recent pipeline cycles</h3></div><a href="/activity">Open audit trail →</a></header>${cycles.length ? renderTable(['Started', 'Trigger', 'Mode', 'Status', 'Current step', 'Explanation'], cycles.map(c => [formatTime(c.created_at), c.trigger, c.mode, statusTag(c.status), c.current_step || '—', escapeHtml(c.summary_json?.explanation || c.last_error || 'In progress')])) : emptyState('No pipeline cycles yet', 'The scheduler heartbeat will create one each minute once the queue worker is running.', null)}</section>`;
}

function handleChange(event) {
    if (event.target.dataset.action !== 'window') return;
    state.windowDays = Number(event.target.value) || 30;
    refresh();
}

function renderResearch(data) {
    const health = data.health || {};
    const candles = health.candles || [];
    const spread = data.spreads || {};
    return `<section class="ops-feature-strip"><div><span class="ops-section-label">Point-in-time foundation</span><h2>${health.open_incidents?.length ? 'Data needs attention' : 'Research data health'}</h2><p>${health.latest_manifest ? `Manifest ${health.latest_manifest.content_hash?.slice(0, 12)}…` : 'No immutable manifest frozen yet'}</p></div><div class="ops-feature-stat"><span>Backtests</span><strong>${data.backtests?.length || 0}</strong></div><div class="ops-feature-stat"><span>Shadow spreads</span><strong>${spread.recent?.length || 0}</strong></div></section>
        <section class="ops-two-column"><article class="ops-panel"><header><div><span class="ops-section-label">Canonical 1-hour bars</span><h3>Candle coverage</h3></div></header>${candles.length ? renderTable(['Asset', 'Latest final', 'Lag', 'Missing 7d', 'Quality'], candles.map(c => [c.asset, formatTime(c.latest_final_bar), c.lag_minutes == null ? '—' : `${c.lag_minutes}m`, c.missing_bars_7d, statusTag(c.quality_state || 'unknown')])) : emptyState('No canonical candle coverage', 'Run the historical backfill or market-data sync.', 'Open operations', 'link', '/operations')}</article>
        <article class="ops-panel"><header><div><span class="ops-section-label">Coinbase / Kraken</span><h3>Spread feasibility</h3></div><span class="ops-state-pill partial">Shadow only</span></header><div class="ops-callout"><strong>Execution remains disabled</strong><p>${spread.classifications?.length || 0} classifications are available for this window. Only observations surviving depth, fees, latency, impact, rebalancing, and the safety buffer can be labeled executable.</p></div>${(spread.classifications || []).map(x => `<div class="ops-key-row"><span>${escapeHtml(x.classification)}</span><strong>${x.total}</strong></div>`).join('')}</article></section>`;
}

async function handleClick(event) {
    const target = event.target.closest('[data-action]');
    if (!target) return;
    const action = target.dataset.action;
    if (action === 'link') return;
    if (action === 'window') return;
    target.disabled = true;
    try {
        if (action === 'cycle' || action === 'diagnostic') await post('/operations/actions/cycles', { broker_account_id: Number(target.dataset.account), trigger: action === 'diagnostic' ? 'diagnostic' : 'manual' });
        if (action === 'sync') await post('/operations/actions/sync', {});
        if (action === 'runtime') await post('/operations/actions/runtime', { process_name: target.dataset.process, action: target.dataset.runtimeAction });
        if (action === 'end-session') {
            if (!confirm('End this paper session? Open positions and pending orders must be resolved first. History will be preserved.')) return;
            await post(`/operations/actions/paper-sessions/${target.dataset.session}/end`, {});
        }
        if (action === 'approve') await post(`/operations/actions/trade-decisions/${target.dataset.decision}/approve`, { actor: 'local-console' });
        if (action === 'reject') await post(`/operations/actions/trade-decisions/${target.dataset.decision}/reject`, { actor: 'local-console', reason: 'Rejected in local console' });
        showToast('Action accepted. The console will refresh as work completes.');
        await refresh();
    } catch (error) {
        showToast(error.message, true);
    } finally {
        target.disabled = false;
    }
}

async function handleSubmit(event) {
    if (event.target.id !== 'paperSessionForm') return;
    event.preventDefault();
    const form = new FormData(event.target);
    const payload = Object.fromEntries(form.entries());
    if (payload.funding_mode === 'mirror') delete payload.virtual_capital;
    try {
        await post('/operations/actions/paper-sessions', payload);
        showToast('Paper session started.');
        await refresh();
    } catch (error) { showToast(error.message, true); }
}

async function post(url, payload) {
    const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(buildActionPayload(payload)) });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'The action could not be completed.');
    return body;
}

function renderEventList(events) {
    if (!events?.length) return emptyState('No activity yet', 'Run a paper cycle to record data, evaluation, and outcome events.', null);
    return `<div class="ops-event-list">${events.map(event => `<article class="ops-event"><span class="ops-event-dot ${event.severity || statusTone(event.status)}"></span><time>${formatTime(event.occurred_at || event.created_at)}</time><div><strong>${escapeHtml(event.title || event.step_key || event.status)}</strong><p>${escapeHtml(event.explanation || event.reason || 'State recorded.')}</p></div><span class="ops-tag">${escapeHtml(event.category || event.status || 'EVENT')}</span></article>`).join('')}</div>`;
}

function renderAssetRows(assets) { return assets.length ? `<div class="ops-asset-rows">${assets.map(a => `<a href="/assets/${a.symbol}" class="ops-asset-row"><span class="ops-coin">${a.symbol[0]}</span><div><strong>${a.symbol}</strong><p>P&amp;L contribution ${formatPct(a.pnl_contribution_pct)} · buy-and-hold price ${formatPct(a.buy_and_hold_price_return_pct)}</p><i style="--bar:${Math.min(100, Math.max(4, Math.abs(a.pnl_contribution_pct || 0) * 12))}%"></i></div><b class="${tone(a.net_pnl)}">${formatMoney(a.net_pnl)}</b></a>`).join('')}</div>` : emptyState('No asset results yet', 'Asset contribution appears after a paper session records evaluations or positions.', null); }
function renderTable(headers, rows) { return rows.length ? `<div class="ops-table-wrap"><table><thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${row.map(cell => `<td>${cell ?? '—'}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : ''; }
function emptyState(title, message, actionLabel = null, action = null, value = null) { const button = actionLabel ? (action === 'link' ? `<a class="ops-button" href="${value}">${actionLabel}</a>` : `<button class="ops-button" data-action="${action}" ${action === 'cycle' ? `data-account="${value}"` : ''}>${actionLabel}</button>`) : ''; return `<div class="ops-empty"><span>○</span><div><strong>${escapeHtml(title)}</strong><p>${escapeHtml(message)}</p>${button}</div></div>`; }
function proposalCard(p) { return `<article class="ops-proposal"><div><strong>${p.asset?.symbol} · ${p.decision}</strong><p>${formatMoney(p.requested_notional)} · expires ${formatTime(p.signal_expires_at)}</p></div><div><button class="ops-button" data-action="reject" data-decision="${p.id}">Reject</button><button class="ops-button primary" data-action="approve" data-decision="${p.id}">Approve paper order</button></div></article>`; }
function processRow(p) { const stateName = p.display_state || p.observed_state; const opposite = p.desired_state === 'paused' ? 'resume' : 'pause'; return `<article class="ops-process"><span class="ops-process-light ${statusTone(stateName)}"></span><div><strong>${escapeHtml(p.metadata_json?.label || p.name)}</strong><p>${escapeHtml(stateName)} · ${p.heartbeat_at ? `heartbeat ${relativeTime(p.heartbeat_at)}` : 'no heartbeat received'}</p></div><div><button class="ops-button compact" data-action="runtime" data-process="${p.name}" data-runtime-action="${opposite}">${opposite === 'pause' ? 'Pause' : 'Resume'}</button><button class="ops-button compact" data-action="runtime" data-process="${p.name}" data-runtime-action="restart">Restart</button></div></article>`; }
function renderSessionHistory(history) { return history.length ? `<section class="ops-panel"><header><h3>Session history</h3></header>${renderTable(['Started', 'Funding', 'Opening cash', 'State'], history.map(s => [formatTime(s.started_at), s.funding_mode, formatMoney(s.opening_cash), statusTag(s.status)]))}</section>` : ''; }
function primaryReason(evaluations) { const held = evaluations.find(e => e.action === 'HOLD'); return held ? `<div class="ops-primary-reason"><span>Primary reason</span><strong>${escapeHtml(held.asset?.symbol || 'Asset')} · ${escapeHtml(held.primary_explanation)}</strong></div>` : ''; }
function cycleHeadline(cycle, session) { if (!session) return 'Paper trading is not funded'; if (!cycle) return 'No cycle has run yet'; if (cycle.status === 'failed') return 'The latest cycle failed'; if (cycle.summary_json?.proposals > 0) return `${cycle.summary_json.proposals} proposal${cycle.summary_json.proposals === 1 ? '' : 's'} created`; if (cycle.status === 'completed') return 'No trade was placed'; return 'A strategy cycle is running'; }
function cycleEvents(cycle) { return cycle?.steps || []; }
function metric(metrics, name) { const value = (metrics || []).find(m => m.metric_name === name)?.metric_value; return value == null ? '—' : formatNumber(value, 2); }
function allHealthy(data) { if (page === 'overview') return Boolean(data.system?.healthy); if (page !== 'operations') return true; return (data.processes || []).length > 0 && data.processes.every(p => p.display_state === 'running'); }
function setRefreshState(label, toneName) { refreshState.className = `ops-state-pill ${toneName}`; refreshState.innerHTML = `<span class="ops-pulse" aria-hidden="true"></span>${label}`; }
function showToast(message, error = false) { toast.hidden = false; toast.className = `ops-toast ${error ? 'error' : ''}`; toast.textContent = message; setTimeout(() => { toast.hidden = true; }, 5000); }
function statusTag(value) { return `<span class="ops-tag ${statusTone(value)}">${escapeHtml(String(value || 'unknown').replaceAll('_', ' '))}</span>`; }
function statusTone(value) { const v = String(value || '').toLowerCase(); if (['running', 'completed', 'succeeded', 'healthy', 'valid', 'active', 'success'].some(x => v.includes(x))) return 'success'; if (['failed', 'error', 'offline', 'rejected', 'expired'].some(x => v.includes(x))) return 'error'; if (['waiting', 'queued', 'warning', 'hold', 'partial', 'paused'].some(x => v.includes(x))) return 'warning'; return 'info'; }
function performanceStateLabel(period) { if (!period || period.measurement_state === 'not_measured') return 'Not measured'; if (period.freshness_state === 'stale') return 'Stale'; if (period.completeness_state === 'incomplete') return 'Incomplete'; return 'Measured'; }
function formatMeasuredPerformance(value, period) { const stateLabel = performanceStateLabel(period); return stateLabel === 'Measured' ? formatPct(value) : stateLabel; }
function performanceDescription(period, measuredDescription) { const stateLabel = performanceStateLabel(period); if (stateLabel === 'Not measured') return 'No eligible paper ledger or replay covers this interval.'; if (stateLabel === 'Incomplete') return 'The requested interval does not yet have complete valuation coverage.'; if (stateLabel === 'Stale') return `Last valuation is ${relativeTime(new Date(Date.now() - Number(period.freshness_seconds || 0) * 1000).toISOString())}.`; return measuredDescription; }
function tone(value) { const n = Number(value || 0); return n > 0 ? 'positive' : n < 0 ? 'negative' : ''; }
function formatMoney(value) { if (value == null || Number.isNaN(Number(value))) return '—'; return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: Math.abs(Number(value)) < 1 ? 4 : 2 }).format(Number(value)); }
function formatPct(value) { return value == null || Number.isNaN(Number(value)) ? '—' : `${Number(value) > 0 ? '+' : ''}${Number(value).toFixed(2)}%`; }
function formatNumber(value, digits = 2) { return value == null ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: digits }); }
function formatTime(value) { if (!value) return '—'; return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(new Date(value)); }
function shortDate(value) { return value ? new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value)) : '—'; }
function relativeTime(value) { const seconds = Math.max(0, Math.round((Date.now() - new Date(value).getTime()) / 1000)); return seconds < 60 ? `${seconds}s ago` : `${Math.floor(seconds / 60)}m ago`; }
function escapeHtml(value) { const div = document.createElement('div'); div.textContent = String(value ?? ''); return div.innerHTML; }
