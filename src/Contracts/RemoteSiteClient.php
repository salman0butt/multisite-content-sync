<?php
/**
 * Remote site client contract.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Contracts;

use SalmanButt\Multisite_Content_Sync\Domain\Connection\Connection;

interface RemoteSiteClient {
	/**
	 * @return array<string, mixed>
	 */
	public function handshake( Connection $connection ): array;

	/**
	 * @param array<string, mixed> $payload Normalized content payload.
	 * @return array<string, mixed>
	 */
	public function sync_content( Connection $connection, array $payload, bool $force = false ): array;
}
