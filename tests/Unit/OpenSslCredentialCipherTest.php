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
		$binary    = base64_decode( substr( $encrypted, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a test ciphertext fixture.

		self::assertIsString( $binary );
		$binary[15] = chr( ord( $binary[15] ) ^ 1 );
		$tampered   = 'v2:' . base64_encode( $binary ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Re-encodes a test ciphertext fixture.

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

		$legacy = base64_encode( $iv . $tag . $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds a legacy binary ciphertext fixture.
		$cipher = new OpenSslCredentialCipher();

		self::assertSame( 'legacy-password', $cipher->decrypt( $legacy ) );
	}

	public function test_empty_credentials_are_rejected(): void {
		$this->expectException( RuntimeException::class );
		( new OpenSslCredentialCipher() )->encrypt( '' );
	}
}
