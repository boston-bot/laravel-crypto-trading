import { escapeMarkup } from './strategy-inspector';

export function metricSeries(metrics = [], names = []) {
    const match = metrics.find(metric => names.includes(metric.name));
    const values = match?.context?.series || match?.context?.values || [];
    return Array.isArray(values) ? values.map(Number).filter(Number.isFinite) : [];
}

function points(values, width = 600, height = 180) {
    if (values.length < 2) return '';
    const low = Math.min(...values);
    const high = Math.max(...values);
    const span = high - low || 1;
    return values.map((value, index) => {
        const x = index / (values.length - 1) * width;
        const y = height - ((value - low) / span * height);
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
}

export function renderEvidenceChart({ label, normal = [], stressed = [], state = 'not_measured' }) {
    if (state !== 'measured' || normal.length < 2) {
        const message = state === 'incomplete' ? 'Incomplete series' : 'Not measured';
        return `<figure class="ops-research-chart empty" aria-label="${escapeMarkup(label)}: ${message}"><figcaption><strong>${escapeMarkup(label)}</strong><span>${message}</span></figcaption><div><span>${message}</span><small>A complete linked out-of-sample series is required before plotting.</small></div></figure>`;
    }
    const normalChange = normal[normal.length - 1] - normal[0];
    const stressedChange = stressed.length > 1 ? stressed[stressed.length - 1] - stressed[0] : null;
    const summary = `Normal series has ${normal.length} observations and changes by ${normalChange.toFixed(2)}. ${stressedChange == null ? 'No complete stressed series.' : `Stressed series has ${stressed.length} observations and changes by ${stressedChange.toFixed(2)}.`}`;
    return `<figure class="ops-research-chart" aria-label="${escapeMarkup(`${label}. ${summary}`)}"><figcaption><strong>${escapeMarkup(label)}</strong><span>Linked OOS · normal / stressed</span></figcaption><svg viewBox="0 0 600 180" role="img" aria-label="${escapeMarkup(summary)}" preserveAspectRatio="none"><polyline class="normal" points="${points(normal)}"></polyline>${stressed.length > 1 ? `<polyline class="stressed" points="${points(stressed)}"></polyline>` : ''}</svg><div class="ops-chart-legend"><span><i></i>Normal</span><span><i></i>Stressed · dashed</span></div><p class="ops-visually-hidden">${escapeMarkup(summary)}</p></figure>`;
}
