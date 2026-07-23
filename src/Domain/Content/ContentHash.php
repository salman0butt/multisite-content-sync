<?php
/**
 * Deterministic content hashing.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Domain\Content;

final class ContentHash {
	/**
	 * Hash the synchronized source fields.
	 *
	 * @param array<string, mixed> $payload Content payload.
	 */
	public static function from_payload( array $payload ): string {
		$canonical = array(
			'post_type'      => $payload['post_type'] ?? null,
			'status'         => $payload['status'] ?? null,
			'title'          => $payload['title'] ?? null,
			'content'        => $payload['content'] ?? null,
			'excerpt'        => $payload['excerpt'] ?? null,
			'slug'           => $payload['slug'] ?? null,
			'date_gmt'       => $payload['date_gmt'] ?? null,
			'taxonomies'     => $payload['taxonomies'] ?? array(),
			'featured_image' => $payload['featured_image'] ?? null,
			'media'          => $payload['media'] ?? array(),
		);

		return self::from_state( $canonical );
	}

	/**
	 * Hash an arbitrary normalized state.
	 *
	 * @param array<string, mixed> $state State to hash.
	 */
	public static function from_state( array $state ): string {
		$normalized = self::normalize( $state );

		try {
			$json = json_encode(
				$normalized,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
			);
		} catch ( \JsonException $error ) {
			throw new \RuntimeException( 'Unable to encode synchronized content for hashing.', 0, $error );
		}

		return hash( 'sha256', $json );
	}

	/**
	 * Normalize nested values and sort associative keys.
	 *
	 * @return mixed
	 */
	private static function normalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::normalize( $item );
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}
}
