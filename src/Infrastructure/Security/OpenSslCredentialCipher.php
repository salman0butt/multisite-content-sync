<?php
/**
 * AES-256-GCM credential encryption.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\Security;

use RuntimeException;
use SalmanButt\Multisite_Content_Sync\Contracts\CredentialCipher;

final class OpenSslCredentialCipher implements CredentialCipher {
	private const CIPHER     = 'aes-256-gcm';
	private const IV_LENGTH  = 12;
	private const TAG_LENGTH = 16;
	private const PREFIX     = 'v2:';
	private const AAD        = 'multisite-content-sync:v2';

	public function encrypt( string $plain_text ): string {
		if ( '' === $plain_text ) {
			throw new RuntimeException( 'An empty credential cannot be encrypted.' );
		}

		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';

		$cipher_text = openssl_encrypt(
			$plain_text,
			self::CIPHER,
			$this->key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			self::AAD,
			self::TAG_LENGTH,
		);

		if ( false === $cipher_text ) {
			throw new RuntimeException( 'Unable to encrypt the credential.' );
		}

		return self::PREFIX . base64_encode( $iv . $tag . $cipher_text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext requires transport-safe encoding.
	}

	public function decrypt( string $cipher_text ): string {
		if ( str_starts_with( $cipher_text, self::PREFIX ) ) {
			return $this->decrypt_payload(
				substr( $cipher_text, strlen( self::PREFIX ) ),
				$this->key(),
				self::AAD,
			);
		}

		// Version 0.1 stored unprefixed payloads with the legacy key derivation.
		return $this->decrypt_payload( $cipher_text, $this->legacy_key(), '' );
	}

	private function decrypt_payload( string $encoded, string $key, string $aad ): string {
		$payload = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the plugin's binary ciphertext envelope.

		if ( false === $payload || strlen( $payload ) <= self::IV_LENGTH + self::TAG_LENGTH ) {
			throw new RuntimeException( 'The encrypted credential is invalid.' );
		}

		$iv         = substr( $payload, 0, self::IV_LENGTH );
		$tag        = substr( $payload, self::IV_LENGTH, self::TAG_LENGTH );
		$encrypted  = substr( $payload, self::IV_LENGTH + self::TAG_LENGTH );
		$plain_text = openssl_decrypt(
			$encrypted,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$aad,
		);

		if ( false === $plain_text ) {
			throw new RuntimeException( 'Unable to decrypt the credential.' );
		}

		return $plain_text;
	}

	private function key(): string {
		return hash_hmac( 'sha256', 'multisite-content-sync', $this->key_material(), true );
	}

	private function legacy_key(): string {
		return hash_hmac( 'sha256', $this->key_material(), 'multisite-content-sync', true );
	}

	private function key_material(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );

		if ( '' === $material ) {
			throw new RuntimeException( 'WordPress security keys are required for credential encryption.' );
		}

		return $material;
	}
}
