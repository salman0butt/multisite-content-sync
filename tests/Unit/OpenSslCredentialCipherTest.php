<?php
/**
 * Credential cipher tests.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Security\OpenSslCredentialCipher;

final class OpenSslCredentialCipherTest extends TestCase {
	public static function setUpBeforeClass(): void {
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'unit-test-auth-key' );
		}

		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', 'unit-test-secure-auth-key' );
		}
	}

	public function test_round_trip_uses_versioned_authenticated_envelope(): void {
		$cipher    = new OpenSslCredentialCipher();
		$encrypted = $cipher->encrypt( 'application-password' );

		self::assertStringStartsWith( 'v2:', $encrypted );
		self::assertSame( 'application-password', $cipher->decrypt( $encrypted ) );
	}

	public function test_tampered_ciphertext_is_rejected(): void {
		$cipher    = new OpenSslCredentialCipher();
		$encrypted = $cipher->encrypt( 'application-password' );
		$last      = substr( $encrypted, -1 );
		$tampered  = substr( $encrypted, 0, -1 ) . ( 'A' === $last ? 'B' : 'A' );

		$this->expectException( RuntimeException::class );
		$cipher->decrypt( $tampered );
	}

	public function test_legacy_version_one_payload_can_be_decrypted(): void {
		$iv       = random_bytes( 12 );
		$tag      = '';
		$material = AUTH_KEY . SECURE_AUTH_KEY;
		$key      = hash_hmac( 'sha256', $material, 'multisite-content-sync', true );
		$payload  = openssl_encrypt(
			'legacy-password',
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16,
		);

		self::assertIsString( $payload );

		$legacy = base64_encode( $iv . $tag . $payload );
		$cipher = new OpenSslCredentialCipher();

		self::assertSame( 'legacy-password', $cipher->decrypt( $legacy ) );
	}

	public function test_empty_credentials_are_rejected(): void {
		$this->expectException( RuntimeException::class );
		( new OpenSslCredentialCipher() )->encrypt( '' );
	}
}
