<?php
/**
 * Utalványlista nézet — szűrés + CSV export + újraküldés + audit.
 *
 * @package Pomodoro_Gift_Vouchers
 * @var PGV_Admin $this
 */

defined( 'ABSPATH' ) || exit;

$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page  = 20;
$offset    = ( $paged - 1 ) * $per_page;

$data     = $this->get_vouchers( array( 's' => $search, 'status' => $status ), $offset, $per_page );
$rows     = $data['rows'];
$total    = $data['total'];
$pages    = max( 1, (int) ceil( $total / $per_page ) );
$labels   = PGV_Vouchers::status_labels();

$export_url = wp_nonce_url(
	add_query_arg(
		array( 'action' => 'pgv_export', 's' => $search, 'status' => $status ),
		admin_url( 'admin-post.php' )
	),
	'pgv_export'
);
?>
<div class="wrap pgv-wrap">
	<h1 class="pgv-title">
		<?php esc_html_e( 'Utalványok', 'pomodoro-gift-vouchers' ); ?>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button pgv-export-btn"><?php esc_html_e( 'CSV export (NAV)', 'pomodoro-gift-vouchers' ); ?></a>
	</h1>

	<form method="get" class="pgv-filter">
		<input type="hidden" name="page" value="<?php echo esc_attr( PGV_Admin::SLUG ); ?>">
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Sorszám, név, e-mail…', 'pomodoro-gift-vouchers' ); ?>">
		<select name="status">
			<option value=""><?php esc_html_e( 'Minden státusz', 'pomodoro-gift-vouchers' ); ?></option>
			<?php foreach ( $labels as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Szűrés', 'pomodoro-gift-vouchers' ); ?></button>
	</form>

	<div class="pgv-card pgv-table-card">
		<table class="widefat striped pgv-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Sorszám', 'pomodoro-gift-vouchers' ); ?></th>
					<th><?php esc_html_e( 'Státusz', 'pomodoro-gift-vouchers' ); ?></th>
					<th><?php esc_html_e( 'Érték', 'pomodoro-gift-vouchers' ); ?></th>
					<th><?php esc_html_e( 'Megajándékozott', 'pomodoro-gift-vouchers' ); ?></th>
					<th><?php esc_html_e( 'Vásárló', 'pomodoro-gift-vouchers' ); ?></th>
					<th><?php esc_html_e( 'E-mail', 'pomodoro-gift-vouchers' ); ?></th>
					<th><?php esc_html_e( 'Lejárat', 'pomodoro-gift-vouchers' ); ?></th>
					<th><?php esc_html_e( 'Műveletek', 'pomodoro-gift-vouchers' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'Nincs találat.', 'pomodoro-gift-vouchers' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $v ) : ?>
						<tr>
							<td>
								<code><?php echo esc_html( $v['serial'] ); ?></code>
								<?php if ( (int) $v['is_legacy'] ) : ?><span class="pgv-badge pgv-badge-legacy">legacy</span><?php endif; ?>
							</td>
							<td><span class="pgv-status pgv-status-<?php echo esc_attr( $v['status'] ); ?>"><?php echo esc_html( PGV_Vouchers::status_label( $v['status'] ) ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( (int) $v['amount'] ) ); ?> Ft</td>
							<td><?php echo esc_html( $v['recipient_name'] ); ?></td>
							<td><?php echo esc_html( $v['giver_name'] ); ?></td>
							<td><?php echo esc_html( $v['delivery_email'] ?: $v['buyer_email'] ); ?></td>
							<td><?php echo esc_html( $v['valid_until'] ); ?></td>
							<td class="pgv-row-actions">
								<button type="button" class="button-link pgv-resend" data-id="<?php echo (int) $v['id']; ?>"><?php esc_html_e( 'E-mail újraküldés', 'pomodoro-gift-vouchers' ); ?></button>
								<button type="button" class="button-link pgv-toggle-audit" data-id="<?php echo (int) $v['id']; ?>"><?php esc_html_e( 'Előzmény', 'pomodoro-gift-vouchers' ); ?></button>
							</td>
						</tr>
						<tr class="pgv-audit-row" id="pgv-audit-<?php echo (int) $v['id']; ?>" style="display:none">
							<td colspan="8">
								<?php
								$audit = PGV_Vouchers::get_audit( (int) $v['id'] );
								if ( empty( $audit ) ) {
									esc_html_e( 'Nincs audit bejegyzés.', 'pomodoro-gift-vouchers' );
								} else {
									echo '<ul class="pgv-audit-list">';
									foreach ( $audit as $a ) {
										printf(
											'<li><strong>%s</strong> — %s → %s <span class="pgv-audit-meta">(%s, %s)</span></li>',
											esc_html( $a['action'] ),
											esc_html( $a['from_status'] ?: '—' ),
											esc_html( $a['to_status'] ?: '—' ),
											esc_html( $a['actor'] ),
											esc_html( $a['occurred_at'] )
										);
									}
									echo '</ul>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="pgv-pagination tablenav">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
			</div>
		<?php endif; ?>
	</div>
</div>
