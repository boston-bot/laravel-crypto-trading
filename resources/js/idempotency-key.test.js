import { describe, expect, it } from 'vitest';
import { buildActionPayload, createIdempotencyKey } from './idempotency-key.js';

const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

describe('createIdempotencyKey', () => {
    it('uses native randomUUID when available', () => {
        const uuid = '9fd67f45-777d-4f89-9db4-f0c916e2815f';
        expect(createIdempotencyKey({ randomUUID: () => uuid })).toBe(uuid);
    });

    it('creates a version 4 UUID with getRandomValues', () => {
        const key = createIdempotencyKey({ getRandomValues: bytes => bytes.fill(17) });
        expect(key).toMatch(uuidPattern);
    });

    it.each([
        [{ randomUUID: () => { throw new Error('blocked'); }, getRandomValues: bytes => bytes.fill(34) }],
        [{ getRandomValues: () => { throw new Error('blocked'); } }],
        [undefined],
    ])('returns a valid key when Web Crypto is unavailable or throws', cryptoApi => {
        const key = createIdempotencyKey(cryptoApi);
        expect(typeof key).toBe('string');
        expect(key.length).toBeGreaterThan(0);
        expect(key.length).toBeLessThan(128);
    });

    it('adds the generated key to action payloads', () => {
        const uuid = '9fd67f45-777d-4f89-9db4-f0c916e2815f';
        expect(buildActionPayload({ funding_mode: 'virtual' }, { randomUUID: () => uuid })).toEqual({
            funding_mode: 'virtual',
            idempotency_key: uuid,
        });
    });
});
