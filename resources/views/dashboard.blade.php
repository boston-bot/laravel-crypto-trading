<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Performance Dashboard | {{ config('app.name', 'Laravel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/dashboard.js'])
</head>
<body class="quant-dashboard">
    <main class="dashboard-shell">
        <header class="dashboard-header">
            <div>
                <p class="dashboard-kicker">Strategy Observatory</p>
                <h1>Performance & Risk Dashboard</h1>
                <p class="dashboard-subtitle">
                    Paper + backtest evaluation with Coinbase-first broker defaults.
                </p>
            </div>
            <span id="syncStatusBadge" class="sync-badge">Awaiting data</span>
        </header>

        <section class="control-panel">
            <div class="control-grid">
                <label class="control-field">
                    <span>Broker</span>
                    <select id="brokerSelect">
                        <option value="coinbase">Coinbase</option>
                        <option value="robinhood">Robinhood</option>
                    </select>
                </label>
                <label class="control-field">
                    <span>Account</span>
                    <select id="accountSelect"></select>
                </label>
                <label class="control-field">
                    <span>Window</span>
                    <select id="windowSelect">
                        <option value="7">7d</option>
                        <option value="30" selected>30d</option>
                        <option value="90">90d</option>
                    </select>
                </label>
                <label class="control-field">
                    <span>Auto Refresh</span>
                    <select id="autoRefreshSelect">
                        <option value="off">Off</option>
                        <option value="30">30s</option>
                        <option value="60" selected>60s</option>
                    </select>
                </label>
                <div class="control-actions">
                    <button id="refreshButton" type="button">Refresh Now</button>
                    <span id="lastUpdatedLabel">Last updated: --</span>
                </div>
            </div>
            <div id="errorBanner" class="error-banner hidden"></div>
        </section>

        <section class="kpi-grid">
            <article class="kpi-card">
                <p>Paper Equity</p>
                <h2 id="kpiPaperEquity">--</h2>
            </article>
            <article class="kpi-card">
                <p>Paper Return</p>
                <h2 id="kpiPaperReturn">--</h2>
            </article>
            <article class="kpi-card">
                <p>Paper Realized PnL</p>
                <h2 id="kpiPaperRealizedPnl">--</h2>
            </article>
            <article class="kpi-card">
                <p>Paper Unrealized PnL</p>
                <h2 id="kpiPaperUnrealizedPnl">--</h2>
            </article>
            <article class="kpi-card">
                <p>Paper Max Drawdown</p>
                <h2 id="kpiPaperDrawdown">--</h2>
            </article>
            <article class="kpi-card">
                <p>Paper Hit Rate</p>
                <h2 id="kpiPaperHitRate">--</h2>
            </article>
            <article class="kpi-card">
                <p>Paper Profit Factor</p>
                <h2 id="kpiPaperProfitFactor">--</h2>
            </article>
            <article class="kpi-card">
                <p>Paper Expectancy</p>
                <h2 id="kpiPaperExpectancy">--</h2>
            </article>
            <article class="kpi-card">
                <p>Paper Trades</p>
                <h2 id="kpiPaperTrades">--</h2>
            </article>
            <article class="kpi-card">
                <p>Avg Hold Hours</p>
                <h2 id="kpiPaperAvgHoldHours">--</h2>
            </article>
            <article class="kpi-card">
                <p>Gross Exposure</p>
                <h2 id="kpiPaperExposure">--</h2>
            </article>
            <article class="kpi-card">
                <p>Heat Score</p>
                <h2 id="kpiPaperHeat">--</h2>
            </article>
            <article class="kpi-card">
                <p>Critical Risk Events</p>
                <h2 id="kpiOpsCriticalRiskEvents">--</h2>
            </article>
            <article class="kpi-card">
                <p>Backtest Sharpe</p>
                <h2 id="kpiBacktestSharpe">--</h2>
            </article>
            <article class="kpi-card">
                <p>Backtest Win Rate</p>
                <h2 id="kpiBacktestWinRate">--</h2>
            </article>
            <article class="kpi-card">
                <p>Backtest Profit Factor</p>
                <h2 id="kpiBacktestProfitFactor">--</h2>
            </article>
        </section>

        <section class="context-grid">
            <article class="context-card">
                <header>
                    <h3>Account Context</h3>
                </header>
                <div id="accountContextGrid" class="context-metric-grid"></div>
            </article>

            <article class="context-card">
                <header>
                    <h3>Window Coverage</h3>
                </header>
                <div id="windowContextGrid" class="context-metric-grid"></div>
            </article>

            <article class="context-card">
                <header>
                    <h3>Execution Health</h3>
                </header>
                <div id="operationsContextGrid" class="context-metric-grid"></div>
            </article>
        </section>

        <section class="context-grid">
            <article class="context-card">
                <header>
                    <h3>Research Engine</h3>
                    <p>Durable queue and immutable manifest identity</p>
                </header>
                <div id="engineContextGrid" class="context-metric-grid"></div>
            </article>
            <article class="context-card">
                <header>
                    <h3>Data Health</h3>
                    <p>Hourly bars, feed gaps, and book freshness</p>
                </header>
                <div id="dataHealthContextGrid" class="context-metric-grid"></div>
            </article>
            <article class="context-card">
                <header>
                    <h3>Spread Feasibility</h3>
                    <p>Coinbase/Kraken shadow observations only</p>
                </header>
                <div id="spreadContextGrid" class="context-metric-grid"></div>
            </article>
        </section>

        <section class="chart-grid">
            <article class="chart-card">
                <header>
                    <h3>Paper Equity Curve</h3>
                    <p>Window performance trajectory</p>
                </header>
                <canvas id="equityChart" height="220"></canvas>
            </article>
            <article class="chart-card">
                <header>
                    <h3>Drawdown Curve</h3>
                    <p>Peak-to-trough stress over time</p>
                </header>
                <canvas id="drawdownChart" height="220"></canvas>
            </article>
            <article class="chart-card">
                <header>
                    <h3>Gross Exposure Curve</h3>
                    <p>Capital utilization through the window</p>
                </header>
                <canvas id="exposureChart" height="220"></canvas>
            </article>
            <article class="chart-card">
                <header>
                    <h3>Portfolio Heat Curve</h3>
                    <p>Risk pressure over time</p>
                </header>
                <canvas id="heatChart" height="220"></canvas>
            </article>
        </section>

        <section class="comparison-card">
            <header>
                <h3>Backtest vs Paper</h3>
                <p>Core metric alignment</p>
            </header>
            <div id="comparisonChart" class="comparison-bars"></div>
        </section>

        <section class="table-grid">
            <article class="table-card">
                <header>
                    <h3>Recent Trades</h3>
                    <p>Expected vs realized attribution</p>
                </header>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Symbol</th>
                                <th>Realized PnL</th>
                                <th>Exp. Prob</th>
                                <th>Exp. Expectancy</th>
                                <th>Return %</th>
                                <th>Hold Hours</th>
                                <th>MAE %</th>
                                <th>MFE %</th>
                            </tr>
                        </thead>
                        <tbody id="tradesTableBody"></tbody>
                    </table>
                </div>
            </article>

            <article class="table-card">
                <header>
                    <h3>Risk Events</h3>
                    <p>Recent warnings and violations</p>
                </header>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Severity</th>
                                <th>Event Type</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody id="riskTableBody"></tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="backtest-card">
            <header>
                <h3>Latest Backtest Run</h3>
                <p id="backtestRunMeta">No completed run found.</p>
            </header>
            <div class="backtest-metrics" id="backtestMetrics"></div>
        </section>
    </main>
</body>
</html>
