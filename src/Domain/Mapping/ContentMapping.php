<?php
/**
 * Source-to-destination mapping.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Domain\Mapping;

use DateTimeImmutable;

final readonly class ContentMapping {
	public const STATUS_PENDING  = 'pending';
	public const STATUS_SYNCED   = 'synced';
	public const STATUS_CONFLICT = 'conflict';
	public const STATUS_FAILED   = 'failed';

	public function __construct(
		public ?int $id,
		public int $connection_id,
		public string $source_object_type,
		public int $source_object_id,
		public ?int $destination_object_id = null,
		public ?string $source_hash = null,
		public ?string $destination_hash = null,
		public string $status = self::STATUS_PENDING,
		public ?DateTimeImmutable $last_synced_at = null,
	) {}
}
