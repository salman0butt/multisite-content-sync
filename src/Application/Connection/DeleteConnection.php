<?php
/**
 * Delete connection use case.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Application\Connection;

use SalmanButt\Multisite_Content_Sync\Contracts\ConnectionRepository;

final readonly class DeleteConnection {
	public function __construct( private ConnectionRepository $connections ) {}

	public function execute( int $id ): bool {
		return $id > 0 && $this->connections->delete( $id );
	}
}
