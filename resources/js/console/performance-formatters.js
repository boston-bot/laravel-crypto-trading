export function measurementLabel(performance) {
    const state = performance?.measurement_state;
    if (!performance || state === 'not_measured') return 'Not measured';
    if (state === 'incomplete') return 'Incomplete';
    if (performance.stale) return 'Stale';
    return 'Measured';
}

export function measuredValue(value, performance, formatter = value => String(value)) {
    const label = measurementLabel(performance);
    return label === 'Measured' && value != null && Number.isFinite(Number(value)) ? formatter(Number(value)) : label;
}

export function formatBps(value) {
    return value == null || !Number.isFinite(Number(value)) ? '—' : `${Number(value).toFixed(1)} bps`;
}

export function formatProbability(value) {
    return value == null || !Number.isFinite(Number(value)) ? '—' : `${(Number(value) * 100).toFixed(1)}%`;
}
