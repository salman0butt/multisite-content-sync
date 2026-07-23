<?php
/**
 * Remote WordPress connection entity.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Domain\Connection;

use DateTimeImmutable;

final readonly class Connection {
	public function __construct(
		public ?int $id,
		public string $name,
		public string $site_url,
		public string $username,
		public string $encrypted_credential,
		public ConnectionStatus $status = ConnectionStatus::Pending,
		public ?string $remote_site_uuid = null,
		public ?string $remote_plugin_version = null,
		public ?DateTimeImmutable $last_checked_at = null,
	) {}

	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->name,
			$this->site_url,
			$this->username,
			$this->encrypted_credential,
			$this->status,
			$this->remote_site_uuid,
			$this->remote_plugin_version,
			$this->last_checked_at,
		);
	}

	public function with_health(
		ConnectionStatus $status,
		?string $remote_site_uuid,
		?string $remote_plugin_version,
		DateTimeImmutable $checked_at,
	): self {
		return new self(
			$this->id,
			$this->name,
			$this->site_url,
			$this->username,
			$this->encrypted_credential,
			$status,
			$remote_site_uuid,
			$remote_plugin_version,
			$checked_at,
		);
	}
}
