<?php
/**
 * Destination conflict exception.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Domain\Content;

use RuntimeException;

final class SyncConflictException extends RuntimeException {
	public function __construct(
		public readonly int $destination_id,
		public readonly string $destination_hash,
	) {
		parent::__construct( 'The destination content was modified after the previous synchronization.' );
	}
}
