<?php
/**
 * WordPress administration menu.
 *
 * @package MultisiteContentSync
 */

declare(strict_types=1);

namespace SalmanButt\Multisite_Content_Sync\Admin;

use SalmanButt\Multisite_Content_Sync\Contracts\ConnectionRepository;
use SalmanButt\Multisite_Content_Sync\Contracts\Hookable;

final readonly class Menu implements Hookable {
	public function __construct( private ConnectionRepository $connections ) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Multisite Content Sync', 'multisite-content-sync' ),
			__( 'Content Sync', 'multisite-content-sync' ),
			'manage_options',
			'multisite-content-sync',
			array( $this, 'render' ),
			'dashicons-update-alt',
			58,
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'multisite-content-sync' ) );
		}

		$connections = $this->connections->all();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Multisite Content Sync', 'multisite-content-sync' ); ?></h1>
			<p>
				<?php
				echo esc_html__(
					'The synchronization backend is active. Connections, manual sync jobs, retries, and cancellations are currently managed through the authenticated REST API.',
					'multisite-content-sync'
				);
				?>
			</p>
			<h2><?php echo esc_html__( 'Destination connections', 'multisite-content-sync' ); ?></h2>
			<?php if ( array() === $connections ) : ?>
				<p>
					<?php
					echo esc_html__(
						'No destination sites are connected. Create one through POST /wp-json/mcs/v1/connections.',
						'multisite-content-sync'
					);
					?>
				</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Name', 'multisite-content-sync' ); ?></th>
							<th><?php echo esc_html__( 'Site', 'multisite-content-sync' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'multisite-content-sync' ); ?></th>
							<th><?php echo esc_html__( 'Last checked', 'multisite-content-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $connections as $connection ) : ?>
							<tr>
								<td><?php echo esc_html( $connection->name ); ?></td>
								<td>
									<a href="<?php echo esc_url( $connection->site_url ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $connection->site_url ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $connection->status->value ); ?></td>
								<td>
									<?php
									echo esc_html(
										null === $connection->last_checked_at
											? __( 'Never', 'multisite-content-sync' )
											: $connection->last_checked_at->format( 'Y-m-d H:i:s T' )
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
