<?php

namespace App\Support\Integrations\Discord;

/**
 * Discord signs every interaction with the application's Ed25519 key: the
 * X-Signature-Ed25519 header over the X-Signature-Timestamp header followed
 * by the raw body, checked against the app's public key.
 */
final class DiscordSignature
{
    public static function valid(string $publicKey, mixed $signature, mixed $timestamp, string $body): bool
    {
        if (! is_string($signature) || ! is_string($timestamp) || $timestamp === ''
            || preg_match('/^[0-9a-f]{128}$/i', $signature) !== 1 || preg_match('/^[0-9a-f]{64}$/i', $publicKey) !== 1) {
            return false;
        }

        $sig = hex2bin($signature);
        $key = hex2bin($publicKey);
        if ($sig === false || $sig === '' || $key === false || $key === '') {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $timestamp.$body, $key);
    }
}
