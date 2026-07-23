<?php
/**
 * Durable synchronization job.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Domain\Sync;

use DateTimeImmutable;

final readonly class SyncJob {
	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_RETRYING   = 'retrying';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_CANCELLED  = 'cancelled';

	public function __construct(
		public int $id,
		public int $connection_id,
		public string $object_type,
		public int $object_id,
		public string $operation,
		public string $status,
		public int $attempts,
		public DateTimeImmutable $available_at,
		public ?DateTimeImmutable $locked_at,
		public string $request_uuid,
		public bool $force,
		public ?string $last_error = null,
	) {}

	public static function active_key(
		int $connection_id,
		string $object_type,
		int $object_id,
		string $operation,
	): string {
		return hash(
			'sha256',
			implode(
				':',
				array(
					(string) $connection_id,
					$object_type,
					(string) $object_id,
					$operation,
				)
			)
		);
	}
}
