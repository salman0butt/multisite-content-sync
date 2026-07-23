<?php
/**
 * Idempotent destination content receiver.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Application\Content;

use InvalidArgumentException;
use RuntimeException;
use SalmanButt\Multisite_Content_Sync\Domain\Content\ContentHash;
use SalmanButt\Multisite_Content_Sync\Domain\Content\SyncConflictException;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Media\BlockContentRewriter;
use SalmanButt\Multisite_Content_Sync\Infrastructure\Media\MediaImporter;
use SalmanButt\Multisite_Content_Sync\Infrastructure\WordPress\IncomingSyncGuard;
use Throwable;
use WP_Error;
use WP_Post;

final readonly class ContentReceiver {
	private const MAPPING_META_KEYS = array(
		'_mcs_source_site_uuid',
		'_mcs_source_object_id',
		'_mcs_source_object_type',
		'_mcs_last_source_hash',
		'_mcs_last_destination_hash',
		'_mcs_last_synced_at',
	);

	public function __construct(
		private MediaImporter $media_importer,
		private BlockContentRewriter $content_rewriter,
		private TermSynchronizer $term_synchronizer,
	) {}

	/**
	 * @param array<string, mixed> $payload Normalized source content.
	 * @return array<string, mixed>
	 */
	public function upsert( array $payload, bool $force = false ): array {
		$this->validate_payload( $payload );

		$source_uuid = strtolower( (string) $payload['source_site_uuid'] );
		$source_id   = (int) $payload['source_object_id'];
		$post_type   = (string) $payload['post_type'];
		$source_hash = (string) $payload['source_hash'];
		$existing_id = $this->find_existing( $source_uuid, $source_id, $post_type );
		$existing    = null === $existing_id ? null : get_post( $existing_id );

		if ( null !== $existing_id && ! $existing instanceof WP_Post ) {
			throw new RuntimeException( 'The mapped destination content could not be loaded.' );
		}

		$current_destination_hash = null;

		if ( $existing instanceof WP_Post ) {
			$current_destination_hash = $this->destination_hash( $existing );
			$stored_destination_hash  = (string) get_post_meta(
				$existing->ID,
				'_mcs_last_destination_hash',
				true,
			);

			if (
				! $force
				&& '' !== $stored_destination_hash
				&& ! hash_equals( $stored_destination_hash, $current_destination_hash )
			) {
				throw new SyncConflictException( $existing->ID, $current_destination_hash );
			}

			$stored_source_hash = (string) get_post_meta(
				$existing->ID,
				'_mcs_last_source_hash',
				true,
			);

			if (
				'' !== $stored_source_hash
				&& hash_equals( $stored_source_hash, $source_hash )
				&& ( '' === $stored_destination_hash || hash_equals( $stored_destination_hash, $current_destination_hash ) )
			) {
				return $this->response(
					$existing,
					$source_hash,
					$current_destination_hash,
					true,
				);
			}
		}

		$taxonomies = is_array( $payload['taxonomies'] ) ? $payload['taxonomies'] : array();
		$this->term_synchronizer->validate( $post_type, $taxonomies );

		/**
		 * Fires before destination media and content are synchronized.
		 *
		 * @param array<string, mixed> $payload     Incoming payload.
		 * @param int|null             $existing_id Existing destination ID.
		 */
		do_action( 'mcs_before_receive_content', $payload, $existing_id );

		$media_map           = array();
		$created_attachments = array();
		$created_terms       = array();
		$post_id             = $existing_id;
		$post_written        = false;
		$created_post        = false;
		$snapshot            = $existing instanceof WP_Post ? $this->snapshot( $existing ) : null;

		try {
			foreach ( $this->media_items( $payload ) as $item ) {
				$imported = $this->media_importer->import( $source_uuid, $item );
				$media_map[ (int) $item['source_attachment_id'] ] = array(
					'id'         => $imported['id'],
					'url'        => $imported['url'],
					'source_url' => $imported['source_url'],
				);

				if ( $imported['created'] ) {
					$created_attachments[] = $imported['id'];
				}
			}

			$rewritten_content = $this->content_rewriter->rewrite(
				(string) $payload['content'],
				$media_map,
			);
			$post_data         = $this->post_data( $payload, $rewritten_content );

			if ( null !== $existing_id ) {
				$post_data['ID'] = $existing_id;
				$result          = IncomingSyncGuard::run(
					static fn (): int|WP_Error => wp_update_post( $post_data, true, false )
				);
			} else {
				$result = IncomingSyncGuard::run(
					static fn (): int|WP_Error => wp_insert_post( $post_data, true, false )
				);
			}

			if ( $result instanceof WP_Error || $result <= 0 ) {
				throw new RuntimeException( 'Unable to save destination content.' );
			}

			$post_id      = $result;
			$post_written = true;
			$created_post = null === $existing_id;

			$this->term_synchronizer->synchronize(
				$post_id,
				$post_type,
				$taxonomies,
				$created_terms,
			);
			$this->synchronize_featured_image( $post_id, $payload['featured_image'], $media_map );

			$destination = get_post( $post_id );

			if ( ! $destination instanceof WP_Post ) {
				throw new RuntimeException( 'The synchronized destination content could not be loaded.' );
			}

			$destination_hash = $this->destination_hash( $destination );

			update_post_meta( $post_id, '_mcs_source_site_uuid', $source_uuid );
			update_post_meta( $post_id, '_mcs_source_object_id', $source_id );
			update_post_meta( $post_id, '_mcs_source_object_type', $post_type );
			update_post_meta( $post_id, '_mcs_last_source_hash', $source_hash );
			update_post_meta( $post_id, '_mcs_last_destination_hash', $destination_hash );
			update_post_meta( $post_id, '_mcs_last_synced_at', current_time( 'mysql', true ) );

			/**
			 * Fires after destination content is synchronized successfully.
			 *
			 * @param int                  $post_id Destination post ID.
			 * @param array<string, mixed> $payload Incoming payload.
			 * @param bool                 $force   Whether conflict protection was bypassed.
			 */
			do_action( 'mcs_after_receive_content', $post_id, $payload, $force );

			return $this->response( $destination, $source_hash, $destination_hash, false );
		} catch ( Throwable $error ) {
			if ( $created_post && null !== $post_id ) {
				IncomingSyncGuard::run(
					static fn (): WP_Post|false|null => wp_delete_post( $post_id, true )
				);
				$post_written = false;
			} elseif ( $post_written && null !== $snapshot && null !== $post_id ) {
				$this->restore_snapshot( $post_id, $snapshot );
				$post_written = false;
			}

			if ( ! $post_written ) {
				$this->cleanup_created_terms( $created_terms );

				foreach ( $created_attachments as $attachment_id ) {
					wp_delete_attachment( $attachment_id, true );
				}
			}

			throw $error;
		}
	}

	/**
	 * @param list<array{taxonomy: string, id: int}> $created_terms Terms created during the failed operation.
	 */
	private function cleanup_created_terms( array $created_terms ): void {
		foreach ( array_reverse( $created_terms ) as $created_term ) {
			$object_ids = get_objects_in_term( $created_term['id'], $created_term['taxonomy'] );

			if ( $object_ids instanceof WP_Error || array() !== $object_ids ) {
				continue;
			}

			wp_delete_term( $created_term['id'], $created_term['taxonomy'] );
		}
	}

	/**
	 * @param array<string, mixed> $payload Incoming payload.
	 */
	private function validate_payload( array $payload ): void {
		$required = array(
			'source_site_uuid',
			'source_object_id',
			'post_type',
			'status',
			'title',
			'content',
			'excerpt',
			'slug',
			'taxonomies',
			'media',
			'source_hash',
		);

		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				throw new InvalidArgumentException( 'A required content field is missing.' );
			}
		}

		$source_uuid = (string) $payload['source_site_uuid'];
		$source_id   = (int) $payload['source_object_id'];
		$post_type   = sanitize_key( (string) $payload['post_type'] );
		$status      = sanitize_key( (string) $payload['status'] );
		$source_hash = strtolower( (string) $payload['source_hash'] );

		if ( ! $this->is_uuid( $source_uuid ) || $source_id <= 0 ) {
			throw new InvalidArgumentException( 'The source content identity is invalid.' );
		}

		$allowed_post_types = apply_filters( 'mcs_allowed_receiver_post_types', array( 'post', 'page' ) );

		if ( ! is_array( $allowed_post_types ) || ! in_array( $post_type, $allowed_post_types, true ) || ! post_type_exists( $post_type ) ) {
			throw new InvalidArgumentException( 'The destination post type is unsupported.' );
		}

		$allowed_statuses = apply_filters(
			'mcs_allowed_receiver_post_statuses',
			array( 'draft', 'pending', 'private', 'publish' ),
		);

		if ( ! is_array( $allowed_statuses ) || ! in_array( $status, $allowed_statuses, true ) ) {
			throw new InvalidArgumentException( 'The destination post status is unsupported.' );
		}

		if (
			! is_string( $payload['title'] )
			|| ! is_string( $payload['content'] )
			|| ! is_string( $payload['excerpt'] )
			|| ! is_string( $payload['slug'] )
			|| strlen( $payload['title'] ) > 1000
			|| strlen( $payload['content'] ) > 5242880
			|| strlen( $payload['excerpt'] ) > 1048576
			|| strlen( $payload['slug'] ) > 200
		) {
			throw new InvalidArgumentException( 'The destination content fields are invalid or too large.' );
		}

		if ( ! is_array( $payload['taxonomies'] ) || ! is_array( $payload['media'] ) || count( $payload['media'] ) > 50 ) {
			throw new InvalidArgumentException( 'The taxonomy or media payload is invalid.' );
		}

		if ( null !== ( $payload['featured_image'] ?? null ) && ! is_array( $payload['featured_image'] ) ) {
			throw new InvalidArgumentException( 'The featured image payload is invalid.' );
		}

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $source_hash ) ) {
			throw new InvalidArgumentException( 'The source content hash is invalid.' );
		}

		$calculated_hash = ContentHash::from_payload( $payload );

		if ( ! hash_equals( $calculated_hash, $source_hash ) ) {
			throw new InvalidArgumentException( 'The source content hash does not match the payload.' );
		}

		if ( null !== ( $payload['date_gmt'] ?? null ) ) {
			$date_gmt = (string) $payload['date_gmt'];

			if (
				1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_gmt )
				|| false === strtotime( $date_gmt . ' UTC' )
			) {
				throw new InvalidArgumentException( 'The source publication date is invalid.' );
			}
		}
	}

	private function find_existing( string $source_uuid, int $source_id, string $post_type ): ?int {
		$ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
				'posts_per_page'         => 2,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => '_mcs_source_site_uuid',
						'value' => $source_uuid,
					),
					array(
						'key'   => '_mcs_source_object_id',
						'value' => (string) $source_id,
					),
					array(
						'key'   => '_mcs_source_object_type',
						'value' => $post_type,
					),
				),
			)
		);

		if ( count( $ids ) > 1 ) {
			throw new RuntimeException( 'Duplicate destination content mappings were detected.' );
		}

		return isset( $ids[0] ) ? (int) $ids[0] : null;
	}

	/**
	 * @param array<string, mixed> $payload Incoming payload.
	 * @return list<array<string, mixed>>
	 */
	private function media_items( array $payload ): array {
		$items = array();

		foreach ( $payload['media'] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['source_attachment_id'] ) ) {
				throw new InvalidArgumentException( 'A media item is invalid.' );
			}

			$items[ (int) $item['source_attachment_id'] ] = $item;
		}

		$featured = $payload['featured_image'] ?? null;

		if ( is_array( $featured ) && isset( $featured['source_attachment_id'] ) ) {
			$items[ (int) $featured['source_attachment_id'] ] = $featured;
		}

		return array_values( $items );
	}

	/**
	 * @param array<string, mixed> $payload Incoming payload.
	 * @return array<string, mixed>
	 */
	private function post_data( array $payload, string $content ): array {
		$data = array(
			'post_type'    => sanitize_key( (string) $payload['post_type'] ),
			'post_status'  => sanitize_key( (string) $payload['status'] ),
			'post_title'   => wp_slash( sanitize_text_field( (string) $payload['title'] ) ),
			'post_content' => wp_slash( wp_kses_post( $content ) ),
			'post_excerpt' => wp_slash( wp_kses_post( (string) $payload['excerpt'] ) ),
			'post_name'    => sanitize_title( (string) $payload['slug'] ),
		);

		if ( null !== ( $payload['date_gmt'] ?? null ) ) {
			$date_gmt              = (string) $payload['date_gmt'];
			$data['post_date_gmt'] = $date_gmt;
			$data['post_date']     = get_date_from_gmt( $date_gmt );
		}

		return $data;
	}

	/**
	 * @param mixed                                                      $featured Featured image payload.
	 * @param array<int, array{id: int, url: string, source_url: string}> $media_map Imported media.
	 */
	private function synchronize_featured_image( int $post_id, mixed $featured, array $media_map ): void {
		if ( null === $featured ) {
			delete_post_thumbnail( $post_id );
			return;
		}

		if ( ! is_array( $featured ) || ! isset( $featured['source_attachment_id'] ) ) {
			throw new InvalidArgumentException( 'The featured image payload is invalid.' );
		}

		$source_id = (int) $featured['source_attachment_id'];

		if ( ! isset( $media_map[ $source_id ] ) || ! set_post_thumbnail( $post_id, $media_map[ $source_id ]['id'] ) ) {
			throw new RuntimeException( 'Unable to set the destination featured image.' );
		}
	}

	private function destination_hash( WP_Post $post ): string {
		$taxonomies = array();

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				continue;
			}

			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );

			if ( $terms instanceof WP_Error ) {
				throw new RuntimeException( 'Unable to calculate destination taxonomy state.' );
			}

			$slugs = array_map( 'strval', $terms );
			sort( $slugs, SORT_STRING );
			$taxonomies[ $taxonomy ] = $slugs;
		}

		$thumbnail_id  = get_post_thumbnail_id( $post );
		$thumbnail_url = $thumbnail_id > 0 ? wp_get_attachment_url( $thumbnail_id ) : false;

		return ContentHash::from_state(
			array(
				'post_type'          => $post->post_type,
				'status'             => $post->post_status,
				'title'              => $post->post_title,
				'content'            => $post->post_content,
				'excerpt'            => $post->post_excerpt,
				'slug'               => $post->post_name,
				'date_gmt'           => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : $post->post_date_gmt,
				'taxonomies'         => $taxonomies,
				'featured_image_url' => false === $thumbnail_url ? null : $thumbnail_url,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function snapshot( WP_Post $post ): array {
		$terms = array();

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			if ( is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				$term_ids             = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				$terms[ $taxonomy ] = $term_ids instanceof WP_Error ? array() : array_map( 'intval', $term_ids );
			}
		}

		$mapping_meta = array();

		foreach ( self::MAPPING_META_KEYS as $meta_key ) {
			$mapping_meta[ $meta_key ] = get_post_meta( $post->ID, $meta_key, true );
		}

		return array(
			'post' => array(
				'ID'            => $post->ID,
				'post_status'   => $post->post_status,
				'post_title'    => wp_slash( $post->post_title ),
				'post_content'  => wp_slash( $post->post_content ),
				'post_excerpt'  => wp_slash( $post->post_excerpt ),
				'post_name'     => $post->post_name,
				'post_date'     => $post->post_date,
				'post_date_gmt' => $post->post_date_gmt,
			),
			'terms'        => $terms,
			'thumbnail'    => get_post_thumbnail_id( $post ),
			'mapping_meta' => $mapping_meta,
		);
	}

	/**
	 * @param array<string, mixed> $snapshot Previous destination state.
	 */
	private function restore_snapshot( int $post_id, array $snapshot ): void {
		$post_data = isset( $snapshot['post'] ) && is_array( $snapshot['post'] )
			? $snapshot['post']
			: array();

		IncomingSyncGuard::run(
			static fn (): int|WP_Error => wp_update_post( $post_data, true, false )
		);

		$terms = isset( $snapshot['terms'] ) && is_array( $snapshot['terms'] )
			? $snapshot['terms']
			: array();

		foreach ( $terms as $taxonomy => $term_ids ) {
			if ( is_array( $term_ids ) ) {
				wp_set_object_terms( $post_id, array_map( 'intval', $term_ids ), (string) $taxonomy, false );
			}
		}

		$thumbnail = isset( $snapshot['thumbnail'] ) ? (int) $snapshot['thumbnail'] : 0;

		if ( $thumbnail > 0 ) {
			set_post_thumbnail( $post_id, $thumbnail );
		} else {
			delete_post_thumbnail( $post_id );
		}

		$mapping_meta = isset( $snapshot['mapping_meta'] ) && is_array( $snapshot['mapping_meta'] )
			? $snapshot['mapping_meta']
			: array();

		foreach ( self::MAPPING_META_KEYS as $meta_key ) {
			$value = $mapping_meta[ $meta_key ] ?? '';

			if ( '' === $value || null === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function response(
		WP_Post $post,
		string $source_hash,
		string $destination_hash,
		bool $no_change,
	): array {
		return array(
			'destination_object_id' => $post->ID,
			'destination_url'       => get_permalink( $post ),
			'modified_gmt'          => $post->post_modified_gmt,
			'source_hash'           => $source_hash,
			'destination_hash'      => $destination_hash,
			'no_change'             => $no_change,
		);
	}

	private function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}
}
