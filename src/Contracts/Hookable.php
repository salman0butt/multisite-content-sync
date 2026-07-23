<?php
/**
 * Hook registration contract.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Contracts;

interface Hookable {
	public function register_hooks(): void;
}
