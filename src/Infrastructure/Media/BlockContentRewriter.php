<?php
/**
 * Rewrite source media references in Gutenberg content.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Infrastructure\Media;

final class BlockContentRewriter {
	/**
	 * @param array<int, array{id: int, url: string, source_url: string}> $media_map Destination media by source ID.
	 */
	public function rewrite( string $content, array $media_map ): string {
		if ( array() === $media_map ) {
			return $content;
		}

		$url_replacements = array();

		foreach ( $media_map as $item ) {
			if ( '' !== $item['source_url'] && '' !== $item['url'] ) {
				$url_replacements[ $item['source_url'] ] = $item['url'];
			}
		}

		$content = strtr( $content, $url_replacements );

		if ( ! str_contains( $content, '<!-- wp:' ) ) {
			return $content;
		}

		$blocks = parse_blocks( $content );
		$blocks = $this->rewrite_blocks( $blocks, $media_map, $url_replacements );

		return serialize_blocks( $blocks );
	}

	/**
	 * @param array<int, array<string, mixed>>                            $blocks           Parsed blocks.
	 * @param array<int, array{id: int, url: string, source_url: string}> $media_map        Destination media by source ID.
	 * @param array<string, string>                                       $url_replacements Source URL map.
	 * @return array<int, array<string, mixed>>
	 */
	private function rewrite_blocks(
		array $blocks,
		array $media_map,
		array $url_replacements,
	): array {
		foreach ( $blocks as &$block ) {
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$block['attrs'] = $this->rewrite_attributes( $block['attrs'], $media_map, $url_replacements );
			}

			if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$block['innerHTML'] = strtr( $block['innerHTML'], $url_replacements );
			}

			if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as &$fragment ) {
					if ( is_string( $fragment ) ) {
						$fragment = strtr( $fragment, $url_replacements );
					}
				}
				unset( $fragment );
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->rewrite_blocks(
					$block['innerBlocks'],
					$media_map,
					$url_replacements,
				);
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * @param array<string, mixed>                                       $attributes       Block attributes.
	 * @param array<int, array{id: int, url: string, source_url: string}> $media_map        Destination media by source ID.
	 * @param array<string, string>                                       $url_replacements Source URL map.
	 * @return array<string, mixed>
	 */
	private function rewrite_attributes(
		array $attributes,
		array $media_map,
		array $url_replacements,
	): array {
		foreach ( array( 'id', 'mediaId', 'attachmentId' ) as $key ) {
			if ( isset( $attributes[ $key ] ) && is_numeric( $attributes[ $key ] ) ) {
				$source_id = (int) $attributes[ $key ];

				if ( isset( $media_map[ $source_id ] ) ) {
					$attributes[ $key ] = $media_map[ $source_id ]['id'];
				}
			}
		}

		if ( isset( $attributes['ids'] ) && is_array( $attributes['ids'] ) ) {
			$attributes['ids'] = array_map(
				static function ( mixed $source_id ) use ( $media_map ): int {
					$id = (int) $source_id;
					return isset( $media_map[ $id ] ) ? $media_map[ $id ]['id'] : $id;
				},
				$attributes['ids']
			);
		}

		foreach ( $attributes as $key => $value ) {
			if ( is_string( $value ) ) {
				$attributes[ $key ] = strtr( $value, $url_replacements );
			} elseif ( is_array( $value ) ) {
				$attributes[ $key ] = $this->rewrite_nested_array( $value, $url_replacements );
			}
		}

		return $attributes;
	}

	/**
	 * @param array<mixed>          $values           Nested values.
	 * @param array<string, string> $url_replacements Source URL map.
	 * @return array<mixed>
	 */
	private function rewrite_nested_array( array $values, array $url_replacements ): array {
		foreach ( $values as $key => $value ) {
			if ( is_string( $value ) ) {
				$values[ $key ] = strtr( $value, $url_replacements );
			} elseif ( is_array( $value ) ) {
				$values[ $key ] = $this->rewrite_nested_array( $value, $url_replacements );
			}
		}

		return $values;
	}
}
