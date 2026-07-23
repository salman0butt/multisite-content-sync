<?php
/**
 * Prevent incoming content from creating outgoing sync loops.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress;

final class IncomingSyncGuard {
	private static int $depth = 0;

	public static function is_active(): bool {
		return self::$depth > 0;
	}

	public static function run( callable $operation ): mixed {
		++self::$depth;

		try {
			return $operation();
		} finally {
			--self::$depth;
		}
	}
}
