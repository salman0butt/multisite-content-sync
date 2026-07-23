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
	private const CIPHER = 'aes-256-gcm';
	private const IV_LENGTH = 12;
	private const TAG_LENGTH = 16;

	public function encrypt( string $plain_text ): string {
		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';

		$cipher_text = openssl_encrypt(
			$plain_text,
			self::CIPHER,
			$this->key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH,
		);

		if ( false === $cipher_text ) {
			throw new RuntimeException( 'Unable to encrypt the credential.' );
		}

		return base64_encode( $iv . $tag . $cipher_text );
	}

	public function decrypt( string $cipher_text ): string {
		$payload = base64_decode( $cipher_text, true );

		if ( false === $payload || strlen( $payload ) <= self::IV_LENGTH + self::TAG_LENGTH ) {
			throw new RuntimeException( 'The encrypted credential is invalid.' );
		}

		$iv         = substr( $payload, 0, self::IV_LENGTH );
		$tag        = substr( $payload, self::IV_LENGTH, self::TAG_LENGTH );
		$encrypted  = substr( $payload, self::IV_LENGTH + self::TAG_LENGTH );
		$plain_text = openssl_decrypt(
			$encrypted,
			self::CIPHER,
			$this->key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
		);

		if ( false === $plain_text ) {
			throw new RuntimeException( 'Unable to decrypt the credential.' );
		}

		return $plain_text;
	}

	private function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );

		if ( '' === $material ) {
			throw new RuntimeException( 'WordPress security keys are required for credential encryption.' );
		}

		return hash_hmac( 'sha256', $material, 'multisite-content-sync', true );
	}
}
