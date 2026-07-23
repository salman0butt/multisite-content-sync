<?php
/**
 * Destination category and tag synchronization.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Application\Content;

use InvalidArgumentException;
use RuntimeException;
use WP_Error;

final class TermSynchronizer {
	/**
	 * @param array<string, mixed> $taxonomies Incoming taxonomy data.
	 */
	public function validate( string $post_type, array $taxonomies ): void {
		if ( count( $taxonomies ) > 20 ) {
			throw new InvalidArgumentException( 'Too many taxonomies were supplied.' );
		}

		foreach ( $taxonomies as $taxonomy => $terms ) {
			if (
				! in_array( $taxonomy, array( 'category', 'post_tag' ), true )
				|| ! taxonomy_exists( $taxonomy )
				|| ! is_object_in_taxonomy( $post_type, $taxonomy )
			) {
				throw new InvalidArgumentException( 'An unsupported taxonomy was supplied.' );
			}

			if ( ! is_array( $terms ) || count( $terms ) > 500 ) {
				throw new InvalidArgumentException( 'The taxonomy term payload is invalid.' );
			}

			foreach ( $terms as $term ) {
				if ( ! is_array( $term ) ) {
					throw new InvalidArgumentException( 'A taxonomy term is invalid.' );
				}

				$name = isset( $term['name'] ) ? sanitize_text_field( (string) $term['name'] ) : '';
				$slug = isset( $term['slug'] ) ? sanitize_title( (string) $term['slug'] ) : '';

				if ( '' === $name || '' === $slug ) {
					throw new InvalidArgumentException( 'Taxonomy term names and slugs are required.' );
				}
			}
		}
	}

	/**
	 * @param array<string, mixed>                   $taxonomies   Incoming taxonomy data.
	 * @param list<array{taxonomy: string, id: int}> $created_terms Terms created during this operation.
	 */
	public function synchronize(
		int $post_id,
		string $post_type,
		array $taxonomies,
		array &$created_terms = array(),
	): void {
		$this->validate( $post_type, $taxonomies );

		foreach ( $taxonomies as $taxonomy => $terms ) {
			$term_ids = array();

			foreach ( $terms as $term ) {
				$name        = sanitize_text_field( (string) $term['name'] );
				$slug        = sanitize_title( (string) $term['slug'] );
				$description = isset( $term['description'] )
					? sanitize_textarea_field( (string) $term['description'] )
					: '';
				$existing    = get_term_by( 'slug', $slug, $taxonomy );

				if ( false !== $existing ) {
					$term_ids[] = (int) $existing->term_id;
					continue;
				}

				$created = wp_insert_term(
					$name,
					$taxonomy,
					array(
						'slug'        => $slug,
						'description' => $description,
					)
				);

				if ( $created instanceof WP_Error ) {
					throw new RuntimeException( 'Unable to create a destination taxonomy term.' );
				}

				$created_id     = (int) $created['term_id'];
				$term_ids[]      = $created_id;
				$created_terms[] = array(
					'taxonomy' => $taxonomy,
					'id'       => $created_id,
				);
			}

			$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );

			if ( $result instanceof WP_Error ) {
				throw new RuntimeException( 'Unable to assign destination taxonomy terms.' );
			}
		}
	}
}
