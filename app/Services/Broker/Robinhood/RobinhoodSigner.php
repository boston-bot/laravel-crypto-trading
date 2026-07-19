<?php

namespace App\Services\Broker\Robinhood;

use RuntimeException;

class RobinhoodSigner
{
    /**
     * @return array<string, string>
     */
    public function buildHeaders(
        string $method,
        string $path,
        string $body,
        string $apiKey,
        string $privateKey,
        ?string $timestamp = null,
    ): array {
        $normalizedMethod = strtoupper($method);
        $timestamp = $timestamp ?? (string) now()->getTimestamp();
        $message = sprintf('%s%s%s%s%s', $apiKey, $timestamp, $path, $normalizedMethod, $body);
        $signature = $this->signMessage($message, $privateKey);

        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'x-api-key' => $apiKey,
            'x-timestamp' => $timestamp,
            'x-signature' => $signature,
        ];
    }

    private function signMessage(string $message, string $privateKey): string
    {
        $normalizedKey = trim($privateKey);

        // PEM private key support.
        if (str_starts_with($normalizedKey, '-----BEGIN')) {
            $signature = '';
            if (openssl_sign($message, $signature, $normalizedKey, OPENSSL_ALGO_SHA256) !== false) {
                return base64_encode($signature);
            }
        }

        // Ed25519 key support for seed (32 bytes) or secret key (64 bytes), base64 encoded.
        $decoded = base64_decode(str_replace(["\r", "\n"], '', $normalizedKey), true);
        if ($decoded !== false && extension_loaded('sodium')) {
            if (strlen($decoded) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                $keypair = sodium_crypto_sign_seed_keypair($decoded);
                $secretKey = sodium_crypto_sign_secretkey($keypair);
                $signature = sodium_crypto_sign_detached($message, $secretKey);

                return base64_encode($signature);
            }

            if (strlen($decoded) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                $signature = sodium_crypto_sign_detached($message, $decoded);

                return base64_encode($signature);
            }
        }

        // Backward-compatible fallback for HMAC-like credentials.
        if ($normalizedKey !== '') {
            return base64_encode(hash_hmac('sha256', $message, $normalizedKey, true));
        }

        throw new RuntimeException('Unable to sign Robinhood request with provided private key.');
    }
}
