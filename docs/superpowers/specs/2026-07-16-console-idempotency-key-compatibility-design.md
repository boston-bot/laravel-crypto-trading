# Console Idempotency-Key Compatibility Design

Date: 2026-07-16  
Status: Approved for implementation planning

## Problem

The operations console creates an idempotency key for every POST request with `crypto.randomUUID()`. Some supported browser contexts expose the Web Crypto API but do not implement `randomUUID`. In those contexts, starting a paper session and every other console action fail in JavaScript before Laravel receives the request.

## Scope

Add one client-side helper in `resources/js/operations-console.js` that returns a UUID-compatible idempotency key.

The helper will:

1. Use `globalThis.crypto.randomUUID()` when it is available.
2. Otherwise use `globalThis.crypto.getRandomValues()` to generate an RFC 4122 version 4-shaped UUID with the correct version and variant bits.
3. Use a timestamp-and-random fallback only when the browser exposes neither secure Web Crypto method. This last fallback is acceptable for request deduplication but is not used as a security token.

Each Web Crypto attempt is exception-safe. If a method exists but throws in a restricted context, the helper catches that failure and proceeds to the next fallback.

All operations-console POST actions will continue to send the key through the existing `idempotency_key` field. No Laravel request contract or database schema changes are required.

## Error Handling

Key generation must not prevent a user action from reaching Laravel in older or restricted browser contexts. Existing API error handling and toast messages remain unchanged.

Every branch must return a non-empty string below the backend's 128-character validation limit.

## Testing

Extract the idempotency-key helper into a side-effect-free JavaScript module so it can be imported without initializing the console DOM. Add Vitest as the repository's lightweight frontend test runner and expose it through an `npm test` script.

Add frontend unit coverage for:

- Native `randomUUID` support.
- `getRandomValues` fallback behavior and UUID shape.
- Absence of both Web Crypto UUID facilities.
- A present `randomUUID` method that throws.
- A present `getRandomValues` method that throws.
- Every branch returning a non-empty string shorter than 128 characters.
- POST payloads continuing to contain an idempotency key.

Keep request-body construction in a small exported function in the same side-effect-free module, allowing the POST payload contract to be tested without mocking `fetch` or importing the DOM-bound console entry point.

Run `npm test`, the focused console feature tests, and the production Vite build after implementation.

## Non-Goals

- Changing backend idempotency semantics.
- Persisting action keys between separate user clicks.
- Adding a UUID dependency.
- Modifying paper-session accounting or approval behavior.
