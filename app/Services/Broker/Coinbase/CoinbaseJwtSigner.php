<?php

namespace App\Services\Broker\Coinbase;

use RuntimeException;

class CoinbaseJwtSigner
{
    public function buildToken(
        string $method,
        string $host,
        string $path,
        string $apiKey,
        string $privateKey,
        ?int $timestamp = null,
    ): string {
        $issuedAt = $timestamp ?? now()->getTimestamp();
        $uri = sprintf('%s %s%s', strtoupper($method), $host, $path);
        $normalizedPrivateKey = $this->normalizePrivateKey($privateKey);
        $nonce = bin2hex(random_bytes(16));

        $payload = [
            'sub' => $apiKey,
            'iss' => 'cdp',
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + 120,
            'uri' => $uri,
        ];

        $header = [
            'typ' => 'JWT',
            'kid' => $apiKey,
            'nonce' => $nonce,
        ];

        if ($this->looksLikePemKey($normalizedPrivateKey)) {
            $header['alg'] = 'ES256';

            return $this->buildEs256Token($header, $payload, $normalizedPrivateKey);
        }

        $header['alg'] = 'EdDSA';

        return $this->buildEdDsaToken($header, $payload, $normalizedPrivateKey);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     */
    private function buildEs256Token(array $header, array $payload, string $privateKey): string
    {
        $encodedHeader = $this->base64UrlEncode($this->encodeJson($header));
        $encodedPayload = $this->base64UrlEncode($this->encodeJson($payload));
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if ($signed === false || $signature === '') {
            throw new RuntimeException('Unable to sign Coinbase JWT with provided EC private key.');
        }

        $joseSignature = $this->ecdsaDerToJose($signature, 64);

        return $signingInput.'.'.$this->base64UrlEncode($joseSignature);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     */
    private function buildEdDsaToken(array $header, array $payload, string $privateKey): string
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('Sodium extension is required for Ed25519 Coinbase API keys.');
        }

        $decodedKey = base64_decode(str_replace(["\r", "\n"], '', $privateKey), true);
        if ($decodedKey === false) {
            throw new RuntimeException('Unable to decode Coinbase Ed25519 private key.');
        }

        $secretKey = match (strlen($decodedKey)) {
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES => $decodedKey,
            SODIUM_CRYPTO_SIGN_SEEDBYTES => sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($decodedKey)),
            default => throw new RuntimeException('Coinbase Ed25519 private key must be 32-byte seed or 64-byte secret key.'),
        };

        $encodedHeader = $this->base64UrlEncode($this->encodeJson($header));
        $encodedPayload = $this->base64UrlEncode($this->encodeJson($payload));
        $signingInput = $encodedHeader.'.'.$encodedPayload;
        $signature = sodium_crypto_sign_detached($signingInput, $secretKey);

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    private function normalizePrivateKey(string $value): string
    {
        $trimmed = trim($value);

        if (str_contains($trimmed, '\n')) {
            return str_replace('\n', PHP_EOL, $trimmed);
        }

        return $trimmed;
    }

    private function looksLikePemKey(string $privateKey): bool
    {
        return str_starts_with($privateKey, '-----BEGIN');
    }

    private function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to JSON encode Coinbase JWT segment.');
        }

        return $encoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function ecdsaDerToJose(string $derSignature, int $partLength): string
    {
        $offset = 0;
        if ($this->readAsn1Tag($derSignature, $offset) !== 0x30) {
            throw new RuntimeException('Invalid DER sequence for Coinbase ES256 signature.');
        }

        $this->readAsn1Length($derSignature, $offset);
        $r = $this->readAsn1Integer($derSignature, $offset);
        $s = $this->readAsn1Integer($derSignature, $offset);

        return str_pad($r, $partLength / 2, "\x00", STR_PAD_LEFT)
            .str_pad($s, $partLength / 2, "\x00", STR_PAD_LEFT);
    }

    private function readAsn1Integer(string $der, int &$offset): string
    {
        if ($this->readAsn1Tag($der, $offset) !== 0x02) {
            throw new RuntimeException('Invalid DER integer in Coinbase ES256 signature.');
        }

        $length = $this->readAsn1Length($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;

        if ($value === false || $value === '') {
            return "\x00";
        }

        return ltrim($value, "\x00") ?: "\x00";
    }

    private function readAsn1Tag(string $der, int &$offset): int
    {
        $tag = ord($der[$offset] ?? "\x00");
        ++$offset;

        return $tag;
    }

    private function readAsn1Length(string $der, int &$offset): int
    {
        $length = ord($der[$offset] ?? "\x00");
        ++$offset;

        if (($length & 0x80) === 0) {
            return $length;
        }

        $byteCount = $length & 0x7F;
        if ($byteCount === 0) {
            throw new RuntimeException('Invalid ASN.1 length encoding.');
        }

        $length = 0;
        for ($i = 0; $i < $byteCount; $i++) {
            $length = ($length << 8) | ord($der[$offset] ?? "\x00");
            ++$offset;
        }

        return $length;
    }
}
