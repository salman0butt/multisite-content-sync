<?php
/**
 * Remote synchronization request exception.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Domain\Sync;

use RuntimeException;

final class RemoteSyncException extends RuntimeException {
	/**
	 * @param array<string, mixed> $details Sanitized remote error data.
	 */
	public function __construct(
		string $message,
		public readonly int $status_code = 0,
		public readonly string $remote_code = 'mcs_remote_error',
		public readonly array $details = array(),
		?\Throwable $previous = null,
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function is_conflict(): bool {
		return 409 === $this->status_code || 'mcs_sync_conflict' === $this->remote_code;
	}
}
