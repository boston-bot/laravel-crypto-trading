export function createIdempotencyKey(cryptoApi = globalThis.crypto) {
    try {
        if (typeof cryptoApi?.randomUUID === 'function') {
            const key = cryptoApi.randomUUID();
            if (isValidKey(key)) return key;
        }
    } catch {
        // Restricted browser contexts can expose randomUUID while rejecting calls.
    }

    try {
        if (typeof cryptoApi?.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);
            cryptoApi.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0'));
            const key = `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
            if (isValidKey(key)) return key;
        }
    } catch {
        // Continue to a non-cryptographic key; this value is for deduplication only.
    }

    return `ops-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

export function buildActionPayload(payload, cryptoApi = globalThis.crypto) {
    return { ...payload, idempotency_key: createIdempotencyKey(cryptoApi) };
}

function isValidKey(value) {
    return typeof value === 'string' && value.length > 0 && value.length < 128;
}
