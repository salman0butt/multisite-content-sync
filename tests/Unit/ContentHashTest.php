<?php
/**
 * Content hash tests.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SalmanButt\Multisite_Content_Sync\Domain\Content\ContentHash;

final class ContentHashTest extends TestCase {
	public function test_associative_key_order_does_not_change_hash(): void {
		$left = array(
			'title' => 'Example',
			'meta'  => array(
				'b' => 2,
				'a' => 1,
			),
		);
		$right = array(
			'meta'  => array(
				'a' => 1,
				'b' => 2,
			),
			'title' => 'Example',
		);

		self::assertSame( ContentHash::from_state( $left ), ContentHash::from_state( $right ) );
	}

	public function test_list_order_remains_significant(): void {
		self::assertNotSame(
			ContentHash::from_state( array( 'items' => array( 1, 2 ) ) ),
			ContentHash::from_state( array( 'items' => array( 2, 1 ) ) ),
		);
	}

	public function test_force_flag_is_not_part_of_source_hash(): void {
		$payload = array(
			'post_type'      => 'post',
			'status'         => 'publish',
			'title'          => 'Title',
			'content'        => 'Content',
			'excerpt'        => '',
			'slug'           => 'title',
			'date_gmt'       => null,
			'taxonomies'     => array(),
			'featured_image' => null,
			'media'          => array(),
		);

		$with_force          = $payload;
		$with_force['force'] = true;

		self::assertSame(
			ContentHash::from_payload( $payload ),
			ContentHash::from_payload( $with_force ),
		);
	}
}
