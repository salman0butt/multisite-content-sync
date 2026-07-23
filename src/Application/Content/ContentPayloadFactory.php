<?php
/**
 * Build normalized source content payloads.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Application\Content;

use InvalidArgumentException;
use SalmanButt\Multisite_Content_Sync\Domain\Content\ContentHash;
use WP_Error;
use WP_Post;
use WP_Term;

final class ContentPayloadFactory {
	/**
	 * @return array<string, mixed>
	 */
	public function build( WP_Post $post ): array {
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			throw new InvalidArgumentException( 'Only posts and pages can be synchronized.' );
		}

		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
			throw new InvalidArgumentException( 'The selected content status cannot be synchronized.' );
		}

		$site_uuid = (string) get_option( 'mcs_site_uuid', '' );

		if ( ! $this->is_uuid( $site_uuid ) ) {
			throw new InvalidArgumentException( 'The source site identity is missing or invalid.' );
		}

		$media_ids       = $this->collect_media_ids( $post );
		$featured_id    = get_post_thumbnail_id( $post );
		$featured_image = $featured_id > 0 ? $this->media_item( $featured_id ) : null;
		$media          = array();

		foreach ( $media_ids as $media_id ) {
			$item = $this->media_item( $media_id );

			if ( null !== $item ) {
				$media[] = $item;
			}
		}

		usort(
			$media,
			static fn ( array $left, array $right ): int =>
				(int) $left['source_attachment_id'] <=> (int) $right['source_attachment_id']
		);

		$payload = array(
			'source_site_uuid' => $site_uuid,
			'source_object_id' => $post->ID,
			'post_type'        => $post->post_type,
			'status'           => $post->post_status,
			'title'            => $post->post_title,
			'content'          => $post->post_content,
			'excerpt'          => $post->post_excerpt,
			'slug'             => $post->post_name,
			'date_gmt'         => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : $post->post_date_gmt,
			'taxonomies'       => $this->taxonomies( $post ),
			'featured_image'   => $featured_image,
			'media'            => $media,
		);

		/**
		 * Filters the outgoing normalized content payload.
		 *
		 * @param array<string, mixed> $payload Outgoing payload.
		 * @param WP_Post              $post    Source post.
		 */
		$filtered = apply_filters( 'mcs_content_payload', $payload, $post );

		if ( ! is_array( $filtered ) ) {
			throw new InvalidArgumentException( 'The filtered content payload must be an array.' );
		}

		$filtered['source_hash'] = ContentHash::from_payload( $filtered );

		return $filtered;
	}

	/**
	 * @return array<string, list<array{name: string, slug: string, description: string}>>
	 */
	private function taxonomies( WP_Post $post ): array {
		$taxonomies = array();

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				continue;
			}

			$terms = wp_get_object_terms( $post->ID, $taxonomy );

			if ( $terms instanceof WP_Error ) {
				throw new InvalidArgumentException( 'Unable to read source taxonomy terms.' );
			}

			$items = array_map(
				static fn ( WP_Term $term ): array => array(
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
				),
				$terms
			);

			usort(
				$items,
				static fn ( array $left, array $right ): int =>
					strcmp( (string) $left['slug'], (string) $right['slug'] )
			);

			$taxonomies[ $taxonomy ] = $items;
		}

		ksort( $taxonomies, SORT_STRING );

		return $taxonomies;
	}

	/**
	 * @return list<int>
	 */
	private function collect_media_ids( WP_Post $post ): array {
		$ids         = array();
		$featured_id = get_post_thumbnail_id( $post );

		if ( $featured_id > 0 ) {
			$ids[ $featured_id ] = true;
		}

		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$id = (int) $match;

				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
		}

		$this->collect_block_media_ids( parse_blocks( $post->post_content ), $ids );
		$this->collect_html_media_ids( $post->post_content, $ids );

		$media_ids = array_map( 'intval', array_keys( $ids ) );
		sort( $media_ids, SORT_NUMERIC );

		return $media_ids;
	}

	/**
	 * Collect attachment IDs from image URLs when block attributes and CSS classes are absent.
	 *
	 * @param array<int, bool> $ids Collected IDs.
	 */
	private function collect_html_media_ids( string $content, array &$ids ): void {
		$processor = new \WP_HTML_Tag_Processor( $content );

		while ( $processor->next_tag( 'img' ) ) {
			$urls = array();
			$src  = $processor->get_attribute( 'src' );

			if ( is_string( $src ) && '' !== $src ) {
				$urls[] = $src;
			}

			$srcset = $processor->get_attribute( 'srcset' );

			if ( is_string( $srcset ) ) {
				foreach ( explode( ',', $srcset ) as $candidate ) {
					$url = trim( explode( ' ', trim( $candidate ) )[0] ?? '' );

					if ( '' !== $url ) {
						$urls[] = $url;
					}
				}
			}

			foreach ( array_unique( $urls ) as $url ) {
				$id = attachment_url_to_postid( $url );

				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<int, bool>                 $ids    Collected IDs.
	 */
	private function collect_block_media_ids( array $blocks, array &$ids ): void {
		foreach ( $blocks as $block ) {
			$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] )
				? $block['attrs']
				: array();

			foreach ( array( 'id', 'mediaId', 'attachmentId' ) as $key ) {
				if ( isset( $attributes[ $key ] ) && is_numeric( $attributes[ $key ] ) ) {
					$id = (int) $attributes[ $key ];

					if ( $id > 0 ) {
						$ids[ $id ] = true;
					}
				}
			}

			if ( isset( $attributes['ids'] ) && is_array( $attributes['ids'] ) ) {
				foreach ( $attributes['ids'] as $attribute_id ) {
					$id = (int) $attribute_id;

					if ( $id > 0 ) {
						$ids[ $id ] = true;
					}
				}
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->collect_block_media_ids( $block['innerBlocks'], $ids );
			}
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function media_item( int $attachment_id ): ?array {
		$attachment = get_post( $attachment_id );
		$url        = wp_get_attachment_url( $attachment_id );
		$mime_type  = (string) get_post_mime_type( $attachment_id );

		if (
			! $attachment instanceof WP_Post
			|| 'attachment' !== $attachment->post_type
			|| false === $url
			|| ! str_starts_with( $mime_type, 'image/' )
		) {
			return null;
		}

		$file_name = wp_basename( (string) get_attached_file( $attachment_id ) );

		if ( '' === $file_name ) {
			$file_name = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		}

		return array(
			'source_attachment_id' => $attachment_id,
			'source_url'           => $url,
			'filename'             => sanitize_file_name( $file_name ),
			'mime_type'            => $mime_type,
			'title'                => $attachment->post_title,
			'alt_text'             => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'              => $attachment->post_excerpt,
			'description'          => $attachment->post_content,
		);
	}

	private function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}
}
