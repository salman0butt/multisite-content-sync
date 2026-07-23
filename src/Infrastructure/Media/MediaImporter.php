<?php
/**
 * Safe destination media importer.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\Media;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_Post;

final readonly class MediaImporter {
	public function __construct( private int $maximum_bytes = 10485760 ) {}

	/**
	 * @param array<string, mixed> $item Normalized media item.
	 * @return array{id: int, url: string, source_url: string, created: bool}
	 */
	public function import( string $source_site_uuid, array $item ): array {
		$source_id  = isset( $item['source_attachment_id'] ) ? (int) $item['source_attachment_id'] : 0;
		$source_url = isset( $item['source_url'] ) ? esc_url_raw( (string) $item['source_url'] ) : '';

		if ( $source_id <= 0 || ! $this->is_uuid( $source_site_uuid ) ) {
			throw new InvalidArgumentException( 'The media source identity is invalid.' );
		}

		if ( ! wp_http_validate_url( $source_url ) || 'https' !== wp_parse_url( $source_url, PHP_URL_SCHEME ) ) {
			throw new InvalidArgumentException( 'Media must use a valid HTTPS URL.' );
		}

		$existing = $this->find_existing( $source_site_uuid, $source_id );

		if ( null !== $existing ) {
			$existing_url  = wp_get_attachment_url( $existing );
			$existing_file = get_attached_file( $existing );

			if (
				false !== $existing_url
				&& is_string( $existing_file )
				&& '' !== $existing_file
				&& file_exists( $existing_file )
			) {
				$this->update_attachment( $existing, $source_site_uuid, $source_id, $item );

				return array(
					'id'         => $existing,
					'url'        => $existing_url,
					'source_url' => $source_url,
					'created'    => false,
				);
			}

			wp_delete_attachment( $existing, true );
		}

		$filename = isset( $item['filename'] )
			? sanitize_file_name( (string) $item['filename'] )
			: '';

		if ( '' === $filename ) {
			$filename = sanitize_file_name( wp_basename( (string) wp_parse_url( $source_url, PHP_URL_PATH ) ) );
		}

		if ( '' === $filename ) {
			throw new InvalidArgumentException( 'The media filename is invalid.' );
		}

		$tmp_file = wp_tempnam( $filename );

		if ( ! is_string( $tmp_file ) || '' === $tmp_file ) {
			throw new RuntimeException( 'Unable to create a temporary media file.' );
		}

		$attachment_id = null;

		try {
			$response = wp_safe_remote_get(
				$source_url,
				array(
					'timeout'             => 30,
					'redirection'         => 3,
					'stream'              => true,
					'filename'            => $tmp_file,
					'limit_response_size' => $this->maximum_bytes + 1,
					'headers'             => array( 'Accept' => 'image/*' ),
				)
			);

			if ( $response instanceof WP_Error ) {
				throw new RuntimeException( 'Unable to download the source media.' );
			}

			$status = wp_remote_retrieve_response_code( $response );
			$size   = filesize( $tmp_file );

			if ( $status < 200 || $status >= 300 ) {
				throw new RuntimeException( 'The source media server returned an error.' );
			}

			if ( false === $size || $size <= 0 || $size > $this->maximum_bytes ) {
				throw new InvalidArgumentException( 'The media file is empty or exceeds the size limit.' );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php'; // @phpstan-ignore requireOnce.fileNotFound (Provided by every WordPress runtime.)
			require_once ABSPATH . 'wp-admin/includes/media.php'; // @phpstan-ignore requireOnce.fileNotFound (Provided by every WordPress runtime.)
			require_once ABSPATH . 'wp-admin/includes/image.php'; // @phpstan-ignore requireOnce.fileNotFound (Provided by every WordPress runtime.)

			$allowed_mimes = array_filter(
				get_allowed_mime_types(),
				static fn ( mixed $mime ): bool => is_string( $mime ) && str_starts_with( $mime, 'image/' )
			);
			$file_check    = wp_check_filetype_and_ext( $tmp_file, $filename, $allowed_mimes );
			$detected_mime = isset( $file_check['type'] ) ? (string) $file_check['type'] : '';

			if ( '' === $detected_mime || ! str_starts_with( $detected_mime, 'image/' ) ) {
				throw new InvalidArgumentException( 'The downloaded file is not an allowed image.' );
			}

			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $tmp_file,
			);
			$attachment_id = media_handle_sideload(
				$file_array,
				0,
				isset( $item['description'] ) ? sanitize_textarea_field( (string) $item['description'] ) : null,
			);

			if ( $attachment_id instanceof WP_Error ) {
				throw new RuntimeException( 'Unable to import the source media.' );
			}

			$tmp_file = '';

			$this->update_attachment( $attachment_id, $source_site_uuid, $source_id, $item );
			$url = wp_get_attachment_url( $attachment_id );

			if ( false === $url ) {
				wp_delete_attachment( $attachment_id, true );
				throw new RuntimeException( 'The imported media URL is unavailable.' );
			}

			return array(
				'id'         => $attachment_id,
				'url'        => $url,
				'source_url' => $source_url,
				'created'    => true,
			);
		} catch ( Throwable $error ) {
			if ( is_int( $attachment_id ) && $attachment_id > 0 ) {
				wp_delete_attachment( $attachment_id, true );
			}

			throw $error;
		} finally {
			if ( '' !== $tmp_file && file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
		}
	}

	private function find_existing( string $source_site_uuid, int $source_id ): ?int {
		$ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 2,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => '_mcs_source_site_uuid',
						'value' => $source_site_uuid,
					),
					array(
						'key'   => '_mcs_source_attachment_id',
						'value' => (string) $source_id,
					),
				),
			)
		);

		if ( count( $ids ) > 1 ) {
			throw new RuntimeException( 'Duplicate destination media mappings were detected.' );
		}

		return isset( $ids[0] ) ? (int) $ids[0] : null;
	}

	/**
	 * @param array<string, mixed> $item Media metadata.
	 */
	private function update_attachment(
		int $attachment_id,
		string $source_site_uuid,
		int $source_id,
		array $item,
	): void {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment instanceof WP_Post ) {
			throw new RuntimeException( 'The imported attachment could not be loaded.' );
		}

		$result = wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_title'   => isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : $attachment->post_title,
				'post_excerpt' => isset( $item['caption'] ) ? wp_kses_post( (string) $item['caption'] ) : '',
				'post_content' => isset( $item['description'] ) ? wp_kses_post( (string) $item['description'] ) : '',
			),
			true,
			false,
		);

		if ( $result instanceof WP_Error ) {
			throw new RuntimeException( 'Unable to update imported media metadata.' );
		}

		update_post_meta( $attachment_id, '_mcs_source_site_uuid', $source_site_uuid );
		update_post_meta( $attachment_id, '_mcs_source_attachment_id', $source_id );
		update_post_meta(
			$attachment_id,
			'_wp_attachment_image_alt',
			isset( $item['alt_text'] ) ? sanitize_text_field( (string) $item['alt_text'] ) : '',
		);
	}

	private function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$value
		);
	}
}
