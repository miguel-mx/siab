<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authenticated encryption for stored secrets (the Scopus / WoS API keys).
 *
 * XSalsa20-Poly1305 via libsodium, with the key derived from APP_SECRET. This
 * protects the keys against someone reading a database dump or a backup; it is not
 * protection against someone who already has the application's own environment,
 * since the derivation input lives there.
 *
 * Rotating APP_SECRET makes existing ciphertexts undecryptable — decrypt() returns
 * null rather than throwing, so the settings screen reports the key as unreadable
 * and offers to set it again instead of taking the page down.
 */
final class SecretBox
{
    private readonly string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $appSecret)
    {
        // APP_SECRET is a hex string of arbitrary length; hash it to exactly the
        // 32 bytes secretbox requires.
        $this->key = sodium_crypto_generichash($appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /** Returns null when the value is corrupt or was encrypted under a different key. */
    public function decrypt(string $ciphertext): ?string
    {
        $raw = base64_decode($ciphertext, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $body = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($body, $nonce, $this->key);

        return $plaintext === false ? null : $plaintext;
    }
}
