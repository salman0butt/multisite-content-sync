<?php
/**
 * Credential cipher contract.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Contracts;

interface CredentialCipher {
	public function encrypt( string $plain_text ): string;

	public function decrypt( string $cipher_text ): string;
}
