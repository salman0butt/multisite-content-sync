<?php
/**
 * Structured activity logger contract.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Contracts;

interface ActivityLogger {
	/**
	 * @param array<string, mixed> $context Non-sensitive context.
	 */
	public function log(
		string $level,
		string $event,
		string $message,
		array $context = array(),
		?string $request_uuid = null,
	): void;
}
