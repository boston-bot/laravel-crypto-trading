import './bootstrap';

const PREFERENCES_KEY = 'dashboard_preferences_v1';

const state = {
    broker: 'coinbase',
    accountId: '',
    windowDays: '30',
    autoRefresh: '60',
    accounts: [],
    timer: null,
    abortController: null,
    payload: null,
    lastSuccessfulAt: null,
};

const ui = {
    brokerSelect: document.getElementById('brokerSelect'),
    accountSelect: document.getElementById('accountSelect'),
    windowSelect: document.getElementById('windowSelect'),
    autoRefreshSelect: document.getElementById('autoRefreshSelect'),
    refreshButton: document.getElementById('refreshButton'),
    lastUpdatedLabel: document.getElementById('lastUpdatedLabel'),
    errorBanner: document.getElementById('errorBanner'),
    syncStatusBadge: document.getElementById('syncStatusBadge'),

    kpiPaperEquity: document.getElementById('kpiPaperEquity'),
    kpiPaperReturn: document.getElementById('kpiPaperReturn'),
    kpiPaperRealizedPnl: document.getElementById('kpiPaperRealizedPnl'),
    kpiPaperUnrealizedPnl: document.getElementById('kpiPaperUnrealizedPnl'),
    kpiPaperDrawdown: document.getElementById('kpiPaperDrawdown'),
    kpiPaperHitRate: document.getElementById('kpiPaperHitRate'),
    kpiPaperProfitFactor: document.getElementById('kpiPaperProfitFactor'),
    kpiPaperExpectancy: document.getElementById('kpiPaperExpectancy'),
    kpiPaperTrades: document.getElementById('kpiPaperTrades'),
    kpiPaperAvgHoldHours: document.getElementById('kpiPaperAvgHoldHours'),
    kpiPaperExposure: document.getElementById('kpiPaperExposure'),
    kpiPaperHeat: document.getElementById('kpiPaperHeat'),
    kpiOpsCriticalRiskEvents: document.getElementById('kpiOpsCriticalRiskEvents'),
    kpiBacktestSharpe: document.getElementById('kpiBacktestSharpe'),
    kpiBacktestWinRate: document.getElementById('kpiBacktestWinRate'),
    kpiBacktestProfitFactor: document.getElementById('kpiBacktestProfitFactor'),

    accountContextGrid: document.getElementById('accountContextGrid'),
    windowContextGrid: document.getElementById('windowContextGrid'),
    operationsContextGrid: document.getElementById('operationsContextGrid'),
    engineContextGrid: document.getElementById('engineContextGrid'),
    dataHealthContextGrid: document.getElementById('dataHealthContextGrid'),
    spreadContextGrid: document.getElementById('spreadContextGrid'),

    equityChart: document.getElementById('equityChart'),
    drawdownChart: document.getElementById('drawdownChart'),
    exposureChart: document.getElementById('exposureChart'),
    heatChart: document.getElementById('heatChart'),
    comparisonChart: document.getElementById('comparisonChart'),
    tradesTableBody: document.getElementById('tradesTableBody'),
    riskTableBody: document.getElementById('riskTableBody'),
    backtestRunMeta: document.getElementById('backtestRunMeta'),
    backtestMetrics: document.getElementById('backtestMetrics'),
};

const chartTheme = {
    grid: '#26404d',
    axis: '#9ab0bd',
    text: '#d8e4ea',
    equity: '#6fd0b8',
    drawdown: '#e08e46',
    drawdownFill: 'rgba(224,142,70,0.2)',
    background: '#132630',
};

document.addEventListener('DOMContentLoaded', () => {
    hydratePreferences();
    bindControls();
    loadAccountsAndRefresh();
    window.addEventListener('resize', debounce(redrawCharts, 150));
});

function hydratePreferences() {
    try {
        const raw = localStorage.getItem(PREFERENCES_KEY);
        if (!raw) {
            return;
        }

        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object') {
            state.broker = safeSelect(parsed.broker, ['coinbase', 'robinhood'], state.broker);
            state.windowDays = safeSelect(String(parsed.windowDays ?? ''), ['7', '30', '90'], state.windowDays);
            state.autoRefresh = safeSelect(String(parsed.autoRefresh ?? ''), ['off', '30', '60'], state.autoRefresh);
        }
    } catch (error) {
        // Ignore malformed localStorage payloads.
    }
}

function persistPreferences() {
    localStorage.setItem(PREFERENCES_KEY, JSON.stringify({
        broker: state.broker,
        windowDays: state.windowDays,
        autoRefresh: state.autoRefresh,
    }));
}

function bindControls() {
    ui.brokerSelect.value = state.broker;
    ui.windowSelect.value = state.windowDays;
    ui.autoRefreshSelect.value = state.autoRefresh;

    ui.brokerSelect.addEventListener('change', async (event) => {
        state.broker = event.target.value;
        state.accountId = '';
        persistPreferences();
        await loadAccountsAndRefresh();
    });

    ui.accountSelect.addEventListener('change', async (event) => {
        state.accountId = event.target.value;
        await refreshDashboard();
    });

    ui.windowSelect.addEventListener('change', async (event) => {
        state.windowDays = event.target.value;
        persistPreferences();
        await refreshDashboard();
    });

    ui.autoRefreshSelect.addEventListener('change', (event) => {
        state.autoRefresh = event.target.value;
        persistPreferences();
        installAutoRefresh();
    });

    ui.refreshButton.addEventListener('click', async () => {
        await refreshDashboard({ syncFirst: true });
    });
}

async function loadAccountsAndRefresh() {
    setLoadingState(true);
    clearError();

    try {
        const response = await fetchJson(`/api/broker/accounts?broker=${encodeURIComponent(state.broker)}&per_page=200`);
        state.accounts = Array.isArray(response?.data) ? response.data : [];
        hydrateAccountSelect();
        await refreshDashboard();
    } catch (error) {
        showError(parseError(error, 'Failed to load broker accounts.'));
        setLoadingState(false);
    }
}

function hydrateAccountSelect() {
    ui.accountSelect.innerHTML = '';

    if (state.accounts.length === 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No accounts';
        ui.accountSelect.appendChild(option);
        state.accountId = '';

        return;
    }

    const preferred = state.accountId || String(state.accounts[0].id);
    const exists = state.accounts.some((account) => String(account.id) === String(preferred));
    state.accountId = exists ? String(preferred) : String(state.accounts[0].id);

    for (const account of state.accounts) {
        const option = document.createElement('option');
        option.value = String(account.id);
        option.textContent = `${account.external_account_id} (#${account.id})`;
        option.selected = String(account.id) === state.accountId;
        ui.accountSelect.appendChild(option);
    }
}

async function refreshDashboard(options = {}) {
    if (!state.accountId) {
        renderEmptyState('No account selected.');
        setLoadingState(false);

        return;
    }

    setLoadingState(true);
    clearError();

    if (state.abortController) {
        state.abortController.abort();
    }
    state.abortController = new AbortController();

    try {
        if (options.syncFirst === true) {
            await runBrokerSync();
        }

        const query = new URLSearchParams({
            broker: state.broker,
            account_id: state.accountId,
            window_days: state.windowDays,
            limit_trades: '100',
            limit_risk_events: '50',
        });
        const payload = await fetchJson(`/api/broker/performance-dashboard?${query.toString()}`, state.abortController.signal);
        state.payload = payload;
        state.lastSuccessfulAt = new Date();
        renderPayload(payload);
        installAutoRefresh();
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        showError(parseError(error, 'Failed to fetch performance dashboard.'));
        if (!state.payload) {
            renderEmptyState('No dashboard data available yet.');
        }
    } finally {
        setLoadingState(false);
    }
}

async function runBrokerSync() {
    const query = new URLSearchParams({
        broker: state.broker,
        timeframe: '1d',
        sync: '1',
    });

    await fetchJson(`/api/broker/sync?${query.toString()}`, undefined, 'POST');
}

function installAutoRefresh() {
    if (state.timer !== null) {
        clearInterval(state.timer);
        state.timer = null;
    }

    if (state.autoRefresh === 'off') {
        return;
    }

    const seconds = Number.parseInt(state.autoRefresh, 10);
    if (!Number.isFinite(seconds) || seconds <= 0) {
        return;
    }

    state.timer = setInterval(() => {
        refreshDashboard();
    }, seconds * 1000);
}

function renderPayload(payload) {
    renderKpis(payload?.kpis ?? {}, payload?.backtest_latest?.metrics ?? {});
    renderContext(payload?.account ?? {}, payload?.window_summary ?? {}, payload?.operations ?? {});
    renderResearch(payload?.research ?? {});
    renderEquityChart(payload?.paper_series?.equity ?? []);
    renderDrawdownChart(payload?.paper_series?.drawdown_pct ?? []);
    renderExposureChart(payload?.paper_series?.gross_exposure_pct ?? []);
    renderHeatChart(payload?.paper_series?.heat_score ?? []);
    renderComparison(payload?.comparison ?? {});
    renderTrades(payload?.recent_trades ?? []);
    renderRiskEvents(payload?.recent_risk_events ?? []);
    renderBacktest(payload?.backtest_latest ?? null);
    renderLastUpdated(payload?.meta?.generated_at ?? null);
    renderSyncBadge(payload);
}

function renderResearch(research) {
    const queue = research?.engine_queue ?? {};
    renderContextGrid(ui.engineContextGrid, [
        ['Pending jobs', formatInteger(queue.pending ?? 0)],
        ['Leased jobs', formatInteger(queue.leased ?? 0)],
        ['Failed jobs', formatInteger(queue.failed ?? 0)],
        ['Manifest', research?.latest_manifest?.content_hash ? String(research.latest_manifest.content_hash).slice(0, 12) : '--'],
    ]);

    const candles = Array.isArray(research?.candles) ? research.candles : [];
    const missing = candles.reduce((sum, row) => sum + Number(row?.missing_bars_7d ?? 0), 0);
    const maxLag = candles.reduce((max, row) => Math.max(max, Number(row?.lag_minutes ?? 0)), 0);
    const books = Array.isArray(research?.books) ? research.books : [];
    const bestBookAge = books.length ? Math.min(...books.map((row) => Number(row?.best_book_age_ms ?? Number.POSITIVE_INFINITY))) : null;
    renderContextGrid(ui.dataHealthContextGrid, [
        ['Universe assets', formatInteger(candles.length)],
        ['Missing bars (7d)', formatInteger(missing)],
        ['Worst 1h lag', Number.isFinite(maxLag) ? `${formatNumber(maxLag, 1)}m` : '--'],
        ['Best book age', Number.isFinite(bestBookAge) ? `${formatInteger(bestBookAge)}ms` : '--'],
    ]);

    const spread = research?.spread_shadow ?? {};
    renderContextGrid(ui.spreadContextGrid, [
        ['Mode', 'Shadow only'],
        ['Observations (24h)', formatInteger(spread.observations_24h ?? 0)],
        ['Executable (24h)', formatInteger(spread.executable_24h ?? 0)],
        ['Max net edge', spread.max_net_edge_bps_24h == null ? '--' : `${formatNumber(spread.max_net_edge_bps_24h, 2)} bps`],
    ]);
}

function renderKpis(kpis, backtestMetrics) {
    ui.kpiPaperEquity.textContent = formatCurrency(kpis.paper_equity);
    ui.kpiPaperReturn.textContent = formatPercent(kpis.paper_return_pct);
    ui.kpiPaperRealizedPnl.textContent = formatCurrency(kpis.paper_realized_pnl);
    ui.kpiPaperUnrealizedPnl.textContent = formatCurrency(kpis.paper_unrealized_pnl);
    ui.kpiPaperDrawdown.textContent = formatPercent(kpis.paper_max_drawdown_pct);
    ui.kpiPaperHitRate.textContent = formatPercent(kpis.paper_hit_rate_pct);
    ui.kpiPaperProfitFactor.textContent = formatNumber(kpis.paper_profit_factor, 3);
    ui.kpiPaperExpectancy.textContent = formatCurrency(kpis.paper_expectancy);
    ui.kpiPaperTrades.textContent = formatInteger(kpis.paper_trade_count);
    ui.kpiPaperAvgHoldHours.textContent = formatNumber(kpis.paper_avg_hold_hours, 2);
    ui.kpiPaperExposure.textContent = formatPercent(kpis.paper_gross_exposure_pct, 2);
    ui.kpiPaperHeat.textContent = formatPercent(kpis.paper_heat_score, 2);
    ui.kpiOpsCriticalRiskEvents.textContent = formatInteger(kpis.operations_critical_risk_events);
    ui.kpiBacktestSharpe.textContent = formatNumber(backtestMetrics.sharpe, 3);
    ui.kpiBacktestWinRate.textContent = formatPercent(backtestMetrics.win_rate_pct, 2);
    ui.kpiBacktestProfitFactor.textContent = formatNumber(backtestMetrics.profit_factor, 3);
}

function renderEquityChart(series) {
    drawLineChart(ui.equityChart, series, {
        stroke: chartTheme.equity,
        fill: null,
        title: 'Equity',
    });
}

function renderDrawdownChart(series) {
    drawLineChart(ui.drawdownChart, series, {
        stroke: chartTheme.drawdown,
        fill: chartTheme.drawdownFill,
        title: 'Drawdown %',
    });
}

function renderExposureChart(series) {
    drawLineChart(ui.exposureChart, series, {
        stroke: '#7ec4ff',
        fill: 'rgba(126,196,255,0.2)',
        title: 'Gross Exposure %',
    });
}

function renderHeatChart(series) {
    drawLineChart(ui.heatChart, series, {
        stroke: '#f0b969',
        fill: 'rgba(240,185,105,0.2)',
        title: 'Heat Score',
    });
}

function renderContext(account, windowSummary, operations) {
    renderContextGrid(ui.accountContextGrid, [
        ['Account Equity', formatCurrency(account.equity)],
        ['Cash Balance', formatCurrency(account.cash_balance)],
        ['Buying Power', formatCurrency(account.buying_power)],
        ['Snapshot', formatDateTime(account.snapshot_at)],
    ]);

    renderContextGrid(ui.windowContextGrid, [
        ['Snapshots', formatInteger(windowSummary.snapshot_count)],
        ['Trades', formatInteger(windowSummary.trade_count)],
        ['Risk Events', formatInteger(windowSummary.risk_event_count)],
        ['Last Snapshot', formatDateTime(windowSummary.last_snapshot_at)],
        ['Last Trade', formatDateTime(windowSummary.last_trade_at)],
        ['Last Risk Event', formatDateTime(windowSummary.last_risk_event_at)],
    ]);

    renderContextGrid(ui.operationsContextGrid, [
        ['Avg Slippage (bps)', formatNumber(operations.avg_slippage_bps, 2)],
        ['Rejected Rate', formatPercent(operations.rejected_order_rate_pct, 2)],
        ['Terminal Events', formatInteger(operations.terminal_order_events)],
        ['Rejected Events', formatInteger(operations.rejected_order_events)],
        ['Paper Staleness (min)', formatNumber(operations.paper_snapshot_staleness_minutes, 1)],
        ['Account Staleness (min)', formatNumber(operations.account_snapshot_staleness_minutes, 1)],
    ]);
}

function renderContextGrid(container, entries) {
    if (!container) {
        return;
    }

    container.innerHTML = entries
        .map(([label, value]) => `<article><p>${escapeHtml(label)}</p><h4>${escapeHtml(value)}</h4></article>`)
        .join('');
}

function renderComparison(comparison) {
    const rows = [
        {
            label: 'Hit / Win Rate',
            paperValue: comparison?.paper?.hit_rate_pct,
            backtestValue: comparison?.backtest?.win_rate_pct,
            unit: '%',
        },
        {
            label: 'Max Drawdown',
            paperValue: comparison?.paper?.max_drawdown_pct,
            backtestValue: comparison?.backtest?.max_drawdown_pct,
            unit: '%',
        },
        {
            label: 'Profit Factor',
            paperValue: comparison?.paper?.profit_factor,
            backtestValue: comparison?.backtest?.profit_factor,
            unit: '',
        },
    ];

    const maxValue = rows.reduce((max, row) => {
        const values = [toFinite(row.paperValue), toFinite(row.backtestValue)].filter((value) => value !== null);
        if (values.length === 0) {
            return max;
        }

        return Math.max(max, ...values);
    }, 1);

    ui.comparisonChart.innerHTML = '';
    rows.forEach((row) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'comparison-row';

        const label = document.createElement('div');
        label.className = 'comparison-label';
        label.textContent = row.label;

        const bars = document.createElement('div');
        bars.className = 'comparison-bar-group';

        bars.appendChild(buildComparisonBar('Paper', row.paperValue, maxValue, 'paper', row.unit));
        bars.appendChild(buildComparisonBar('Backtest', row.backtestValue, maxValue, 'backtest', row.unit));

        wrapper.appendChild(label);
        wrapper.appendChild(bars);
        ui.comparisonChart.appendChild(wrapper);
    });
}

function buildComparisonBar(label, value, maxValue, variant, unit) {
    const item = document.createElement('div');
    item.className = `comparison-bar-item ${variant}`;

    const top = document.createElement('div');
    top.className = 'comparison-bar-meta';
    top.innerHTML = `<span>${escapeHtml(label)}</span><span>${formatNumber(value, 2)}${escapeHtml(unit)}</span>`;

    const rail = document.createElement('div');
    rail.className = 'comparison-rail';

    const fill = document.createElement('div');
    fill.className = 'comparison-fill';

    const finite = toFinite(value);
    const widthPct = finite === null || maxValue <= 0
        ? 0
        : Math.max(0, Math.min(100, (finite / maxValue) * 100));
    fill.style.width = `${widthPct}%`;

    rail.appendChild(fill);
    item.appendChild(top);
    item.appendChild(rail);

    return item;
}

function renderTrades(trades) {
    if (!Array.isArray(trades) || trades.length === 0) {
        ui.tradesTableBody.innerHTML = '<tr><td colspan="9" class="empty-cell">No paper trades in this window.</td></tr>';

        return;
    }

    ui.tradesTableBody.innerHTML = trades.map((trade) => {
        const pnl = toFinite(trade.realized_pnl);
        const pnlClass = pnl === null ? '' : pnl >= 0 ? 'positive' : 'negative';

        return `<tr>
            <td>${formatDateTime(trade.attributed_at)}</td>
            <td>${escapeHtml(trade.asset_symbol ?? '--')}</td>
            <td class="${pnlClass}">${formatCurrency(trade.realized_pnl)}</td>
            <td>${formatPercent(trade.expected_probability, 2, true)}</td>
            <td>${formatCurrency(trade.expected_expectancy)}</td>
            <td>${formatPercent(trade.realized_return_pct, 2)}</td>
            <td>${formatInteger(trade.hold_hours)}</td>
            <td>${formatPercent(trade.mae_pct, 2)}</td>
            <td>${formatPercent(trade.mfe_pct, 2)}</td>
        </tr>`;
    }).join('');
}

function renderRiskEvents(events) {
    if (!Array.isArray(events) || events.length === 0) {
        ui.riskTableBody.innerHTML = '<tr><td colspan="4" class="empty-cell">No risk events in this window.</td></tr>';

        return;
    }

    ui.riskTableBody.innerHTML = events.map((event) => `<tr>
        <td>${formatDateTime(event.triggered_at)}</td>
        <td><span class="severity-chip severity-${escapeHtml((event.severity ?? 'info').toLowerCase())}">${escapeHtml(event.severity ?? 'info')}</span></td>
        <td>${escapeHtml(event.event_type ?? '--')}</td>
        <td>${escapeHtml(event.message ?? '--')}</td>
    </tr>`).join('');
}

function renderBacktest(backtest) {
    if (!backtest || !backtest.run_id) {
        ui.backtestRunMeta.textContent = 'No completed backtest runs found.';
        ui.backtestMetrics.innerHTML = '<p class="empty-backtest">Run strategy evaluation to populate backtest metrics.</p>';

        return;
    }

    ui.backtestRunMeta.textContent = `Run #${backtest.run_id} completed ${formatDateTime(backtest.completed_at)}.`;
    const metrics = backtest.metrics ?? {};
    const cards = [
        ['Sharpe', formatNumber(metrics.sharpe, 3)],
        ['Max Drawdown', `${formatNumber(metrics.max_drawdown_pct, 2)}%`],
        ['Win Rate', `${formatNumber(metrics.win_rate_pct, 2)}%`],
        ['Profit Factor', formatNumber(metrics.profit_factor, 3)],
    ];

    ui.backtestMetrics.innerHTML = cards
        .map(([label, value]) => `<article><p>${escapeHtml(label)}</p><h4>${escapeHtml(value)}</h4></article>`)
        .join('');
}

function renderSyncBadge(payload) {
    const hasSeries = Array.isArray(payload?.paper_series?.equity) && payload.paper_series.equity.length > 0;
    const hasBacktest = Boolean(payload?.backtest_latest?.run_id);

    if (hasSeries && hasBacktest) {
        ui.syncStatusBadge.textContent = 'Data healthy';
        ui.syncStatusBadge.className = 'sync-badge healthy';

        return;
    }

    if (hasSeries || hasBacktest) {
        ui.syncStatusBadge.textContent = 'Partial data';
        ui.syncStatusBadge.className = 'sync-badge partial';

        return;
    }

    ui.syncStatusBadge.textContent = 'Insufficient data';
    ui.syncStatusBadge.className = 'sync-badge degraded';
}

function renderLastUpdated(generatedAt) {
    const sourceDate = generatedAt ? new Date(generatedAt) : state.lastSuccessfulAt;
    ui.lastUpdatedLabel.textContent = `Last updated: ${sourceDate ? sourceDate.toLocaleString() : '--'}`;
}

function redrawCharts() {
    if (!state.payload) {
        return;
    }

    renderEquityChart(state.payload?.paper_series?.equity ?? []);
    renderDrawdownChart(state.payload?.paper_series?.drawdown_pct ?? []);
    renderExposureChart(state.payload?.paper_series?.gross_exposure_pct ?? []);
    renderHeatChart(state.payload?.paper_series?.heat_score ?? []);
}

function setLoadingState(isLoading) {
    ui.refreshButton.disabled = isLoading;
    ui.refreshButton.textContent = isLoading ? 'Refreshing…' : 'Refresh Now';
}

function renderEmptyState(message) {
    showError(message);
    renderKpis({}, {});
    renderContext({}, {}, {});
    renderEquityChart([]);
    renderDrawdownChart([]);
    renderExposureChart([]);
    renderHeatChart([]);
    renderComparison({});
    renderTrades([]);
    renderRiskEvents([]);
    renderBacktest(null);
    ui.syncStatusBadge.textContent = 'No data';
    ui.syncStatusBadge.className = 'sync-badge degraded';
}

function showError(message) {
    ui.errorBanner.classList.remove('hidden');
    ui.errorBanner.textContent = message;
}

function clearError() {
    ui.errorBanner.classList.add('hidden');
    ui.errorBanner.textContent = '';
}

async function fetchJson(url, signal = undefined, method = 'GET') {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
        },
        signal,
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = typeof payload?.message === 'string'
            ? payload.message
            : `Request failed with status ${response.status}.`;
        const error = new Error(message);
        error.name = 'HttpError';
        throw error;
    }

    return payload;
}

function parseError(error, fallback) {
    if (error && typeof error.message === 'string' && error.message.length > 0) {
        return error.message;
    }

    return fallback;
}

function drawLineChart(canvas, series, options) {
    if (!canvas) {
        return;
    }

    const context = canvas.getContext('2d');
    if (!context) {
        return;
    }

    const width = canvas.clientWidth || canvas.parentElement?.clientWidth || 640;
    const height = Number.parseInt(canvas.getAttribute('height') || '220', 10);
    const dpr = window.devicePixelRatio || 1;
    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    context.scale(dpr, dpr);

    context.clearRect(0, 0, width, height);
    context.fillStyle = chartTheme.background;
    context.fillRect(0, 0, width, height);

    const margin = { top: 16, right: 16, bottom: 24, left: 54 };
    const chartWidth = width - margin.left - margin.right;
    const chartHeight = height - margin.top - margin.bottom;

    drawGrid(context, margin, chartWidth, chartHeight);

    if (!Array.isArray(series) || series.length < 2) {
        context.fillStyle = chartTheme.axis;
        context.font = '12px ui-monospace, Menlo, monospace';
        context.fillText('Insufficient series data.', margin.left + 10, margin.top + 20);

        return;
    }

    const values = series.map((point) => toFinite(point.v)).filter((value) => value !== null);
    if (values.length < 2) {
        return;
    }

    const minValue = Math.min(...values);
    const maxValue = Math.max(...values);
    const paddedRange = maxValue - minValue;
    const lower = minValue - paddedRange * 0.05;
    const upper = maxValue + paddedRange * 0.05;
    const safeRange = upper - lower === 0 ? 1 : upper - lower;

    const points = series.map((point, index) => {
        const value = toFinite(point.v) ?? 0;
        const x = margin.left + (index / (series.length - 1)) * chartWidth;
        const y = margin.top + chartHeight - ((value - lower) / safeRange) * chartHeight;

        return { x, y, value };
    });

    if (options.fill) {
        context.beginPath();
        context.moveTo(points[0].x, margin.top + chartHeight);
        points.forEach((point) => context.lineTo(point.x, point.y));
        context.lineTo(points[points.length - 1].x, margin.top + chartHeight);
        context.closePath();
        context.fillStyle = options.fill;
        context.fill();
    }

    context.beginPath();
    points.forEach((point, index) => {
        if (index === 0) {
            context.moveTo(point.x, point.y);
        } else {
            context.lineTo(point.x, point.y);
        }
    });
    context.strokeStyle = options.stroke;
    context.lineWidth = 2;
    context.stroke();

    const lastPoint = points[points.length - 1];
    context.fillStyle = options.stroke;
    context.beginPath();
    context.arc(lastPoint.x, lastPoint.y, 3, 0, Math.PI * 2);
    context.fill();

    context.fillStyle = chartTheme.text;
    context.font = '11px ui-monospace, Menlo, monospace';
    context.fillText(formatNumber(maxValue, 2), 8, margin.top + 8);
    context.fillText(formatNumber(minValue, 2), 8, margin.top + chartHeight);
}

function drawGrid(context, margin, chartWidth, chartHeight) {
    context.strokeStyle = chartTheme.grid;
    context.lineWidth = 1;

    const lines = 4;
    for (let i = 0; i <= lines; i++) {
        const y = margin.top + (i / lines) * chartHeight;
        context.beginPath();
        context.moveTo(margin.left, y);
        context.lineTo(margin.left + chartWidth, y);
        context.stroke();
    }
}

function safeSelect(value, allowed, fallback) {
    return allowed.includes(value) ? value : fallback;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function toFinite(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const parsed = Number.parseFloat(String(value));

    return Number.isFinite(parsed) ? parsed : null;
}

function formatNumber(value, decimals = 2) {
    const parsed = toFinite(value);
    if (parsed === null) {
        return '--';
    }

    return parsed.toFixed(decimals);
}

function formatInteger(value) {
    const parsed = toFinite(value);
    if (parsed === null) {
        return '--';
    }

    return `${Math.round(parsed)}`;
}

function formatCurrency(value) {
    const parsed = toFinite(value);
    if (parsed === null) {
        return '--';
    }

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(parsed);
}

function formatPercent(value, decimals = 2, normalizeRatio = false) {
    const parsed = toFinite(value);
    if (parsed === null) {
        return '--';
    }

    const normalized = normalizeRatio && Math.abs(parsed) <= 1 ? parsed * 100 : parsed;

    return `${normalized.toFixed(decimals)}%`;
}

function formatDateTime(value) {
    if (!value) {
        return '--';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '--';
    }

    return date.toLocaleString();
}

function debounce(callback, delayMs) {
    let timeout = null;

    return (...args) => {
        if (timeout) {
            clearTimeout(timeout);
        }

        timeout = setTimeout(() => callback(...args), delayMs);
    };
}
