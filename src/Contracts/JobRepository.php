<?php
/**
 * Durable job repository contract.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Contracts;

use DateTimeImmutable;
use SalmanButt\Multisite_Content_Sync\Domain\Sync\SyncJob;

interface JobRepository {
	public function enqueue(
		int $connection_id,
		string $object_type,
		int $object_id,
		string $operation,
		bool $force,
	): SyncJob;

	/**
	 * @return list<SyncJob>
	 */
	public function claim_batch(
		int $limit,
		DateTimeImmutable $now,
		DateTimeImmutable $stale_before,
	): array;

	public function complete( int $id, DateTimeImmutable $completed_at ): void;

	public function retry_later( int $id, DateTimeImmutable $available_at, string $error ): void;

	public function fail( int $id, DateTimeImmutable $failed_at, string $error ): void;

	public function retry( int $id ): bool;

	public function cancel( int $id ): bool;

	/**
	 * @return list<SyncJob>
	 */
	public function latest( int $limit = 100 ): array;
}
