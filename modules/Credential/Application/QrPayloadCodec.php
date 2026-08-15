<?php

declare(strict_types=1);

namespace Modules\Credential\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds and reads the QR payload (ADR 0011 §3).
 *
 * WHAT IS IN IT: a credential serial, an expiry, a single-use nonce, a key id, and a
 * signature over those. Five fields.
 *
 * WHAT IS NOT IN IT: the holder's name, birth date, address, sectors, income, photo, case
 * history or account. A QR code is photographed, screenshotted, forwarded and left on
 * counters. Anything encoded in it is public the moment it is displayed, so the payload
 * carries a *handle* and the server answers questions about it — it is not a portable copy
 * of the record.
 *
 * REPLAY RESISTANCE has two independent layers, because either alone is weak:
 *   * a short expiry, so a photographed code dies on its own; and
 *   * a single-use nonce enforced by a unique index, so it cannot be used twice even
 *     within its window.
 *
 * The signature is HMAC-SHA256 with a server-held key. Symmetric is right here because
 * only this backend both issues and verifies — no third party needs to verify offline
 * yet. Offline verification by a kiosk would need asymmetric keys and its own ADR.
 */
final class QrPayloadCodec
{
    private const VERSION = 1;

    /**
     * @return array{payload: string, expires_at: Carbon, nonce: string}
     */
    public function encode(string $serial, string $keyId): array
    {
        $expiresAt = now()->addSeconds((int) config('credential.qr.ttl_seconds'));
        $nonce = Str::random(24);

        $body = [
            'v' => self::VERSION,
            's' => $serial,
            'e' => $expiresAt->getTimestamp(),
            'n' => $nonce,
            'k' => $keyId,
        ];

        $encoded = self::base64UrlEncode((string) json_encode($body));
        $signature = self::base64UrlEncode($this->sign($encoded, $keyId));

        return [
            'payload' => $encoded.'.'.$signature,
            'expires_at' => $expiresAt,
            'nonce' => $nonce,
        ];
    }

    /**
     * Decodes and verifies a payload.
     *
     * Returns null for anything that is not a well-formed, correctly signed payload —
     * malformed, tampered, unknown key, wrong version. The caller reports `malformed` or
     * `signature-invalid` without saying which field was wrong, because a decoder that
     * explains itself is a decoder that helps someone forge the next attempt.
     *
     * @return array{serial: string, expires_at: Carbon, nonce: string, key_id: string}|null
     */
    public function decode(string $payload): ?array
    {
        $parts = explode('.', $payload);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        $body = json_decode((string) self::base64UrlDecode($encoded), true);

        if (! is_array($body) || ($body['v'] ?? null) !== self::VERSION) {
            return null;
        }

        $keyId = (string) ($body['k'] ?? '');

        if ($this->keyFor($keyId) === null) {
            return null;
        }

        // Constant-time comparison: a byte-by-byte early exit leaks how much of a forged
        // signature was correct, which is enough to construct one.
        if (! hash_equals(self::base64UrlEncode($this->sign($encoded, $keyId)), $signature)) {
            return null;
        }

        return [
            'serial' => (string) ($body['s'] ?? ''),
            'expires_at' => Carbon::createFromTimestamp((int) ($body['e'] ?? 0)),
            'nonce' => (string) ($body['n'] ?? ''),
            'key_id' => $keyId,
        ];
    }

    public function activeKeyId(): string
    {
        return (string) config('credential.qr.active_key_id');
    }

    private function sign(string $encoded, string $keyId): string
    {
        return hash_hmac('sha256', $encoded, (string) $this->keyFor($keyId), true);
    }

    /**
     * Key material comes from configuration, which comes from the environment. It is never
     * stored in the database, never returned by an endpoint and never logged
     * (CLAUDE.md Article 5.6) — the credential row records only which key id sealed it, so
     * a key can be retired without invalidating everything else.
     */
    private function keyFor(string $keyId): ?string
    {
        /** @var array<string, string> $keys */
        $keys = (array) config('credential.qr.keys');

        return $keys[$keyId] ?? null;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
