<?php
/**
 * Connection deletion guard exception.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Domain\Connection;

use RuntimeException;

final class ConnectionInUseException extends RuntimeException {}
