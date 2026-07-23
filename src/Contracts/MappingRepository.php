<?php
/**
 * Content mapping repository contract.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Contracts;

use SalmanButt\Multisite_Content_Sync\Domain\Mapping\ContentMapping;

interface MappingRepository {
	public function find(
		int $connection_id,
		string $source_object_type,
		int $source_object_id,
	): ?ContentMapping;

	public function save( ContentMapping $mapping ): ContentMapping;
}
