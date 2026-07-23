<?php
/**
 * Connection repository contract.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Contracts;

use SalmanButt\Multisite_Content_Sync\Domain\Connection\Connection;

interface ConnectionRepository {
	/**
	 * @return list<Connection>
	 */
	public function all(): array;

	public function find( int $id ): ?Connection;

	public function find_by_site_url( string $site_url ): ?Connection;

	public function save( Connection $connection ): Connection;

	public function delete( int $id ): bool;
}
