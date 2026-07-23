<?php
/**
 * Create connection use case.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Application\Connection;

use InvalidArgumentException;
use SalmanButt\Multisite_Content_Sync\Contracts\ConnectionRepository;
use SalmanButt\Multisite_Content_Sync\Contracts\CredentialCipher;
use SalmanButt\Multisite_Content_Sync\Domain\Connection\Connection;

final readonly class CreateConnection {
	public function __construct(
		private ConnectionRepository $connections,
		private CredentialCipher $cipher,
	) {}

	public function execute(
		string $name,
		string $site_url,
		string $username,
		string $application_password,
	): Connection {
		$name                 = sanitize_text_field( $name );
		$site_url             = untrailingslashit( esc_url_raw( $site_url ) );
		$username             = sanitize_user( $username, true );
		$normalized_password  = preg_replace( '/\s+/', '', $application_password );
		$application_password = is_string( $normalized_password ) ? $normalized_password : '';

		if ( '' === $name || '' === $username || '' === $application_password ) {
			throw new InvalidArgumentException( 'Name, username, and application password are required.' );
		}

		if ( strlen( $site_url ) > 190 ) {
			throw new InvalidArgumentException( 'The destination URL is too long.' );
		}

		if ( ! wp_http_validate_url( $site_url ) || 'https' !== wp_parse_url( $site_url, PHP_URL_SCHEME ) ) {
			throw new InvalidArgumentException( 'The destination must be a valid HTTPS URL.' );
		}

		if ( untrailingslashit( home_url( '/' ) ) === $site_url ) {
			throw new InvalidArgumentException( 'The current site cannot be added as its own destination.' );
		}

		if ( null !== $this->connections->find_by_site_url( $site_url ) ) {
			throw new InvalidArgumentException( 'A connection for this destination already exists.' );
		}

		return $this->connections->save(
			new Connection(
				null,
				$name,
				$site_url,
				$username,
				$this->cipher->encrypt( $application_password ),
			)
		);
	}
}
