<?php
/**
 * Connection health status.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Domain\Connection;

enum ConnectionStatus: string {
	case Pending   = 'pending';
	case Connected = 'connected';
	case Failed    = 'failed';
}
