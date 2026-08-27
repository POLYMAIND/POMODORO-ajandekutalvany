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
$edit_id   = (int) ( $_GET['edit'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
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
								<?php if ( ! empty( $v['seq_no'] ) ) : ?>
									<div class="pgv-seq description"><?php echo esc_html( sprintf( __( 'belső sorszám: %1$s/%2$06d', 'pomodoro-gift-vouchers' ), $v['seq_year'], $v['seq_no'] ) ); ?></div>
								<?php endif; ?>
								<?php if ( (int) $v['is_legacy'] ) : ?><span class="pgv-badge pgv-badge-legacy">legacy</span><?php endif; ?>
							</td>
							<td><span class="pgv-status pgv-status-<?php echo esc_attr( $v['status'] ); ?>"><?php echo esc_html( PGV_Vouchers::status_label( $v['status'] ) ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( (int) $v['amount'] ) ); ?> Ft</td>
							<td><?php echo esc_html( $v['recipient_name'] ); ?></td>
							<td><?php echo esc_html( $v['giver_name'] ); ?></td>
							<td><?php echo esc_html( $v['delivery_email'] ?: $v['buyer_email'] ); ?></td>
							<td><?php echo esc_html( $v['valid_until'] ); ?></td>
							<td class="pgv-row-actions">
								<?php
								$edit_url = add_query_arg(
									array( 'page' => PGV_Admin::SLUG, 's' => $search, 'status' => $status, 'paged' => $paged, 'edit' => (int) $v['id'] ),
									admin_url( 'admin.php' )
								) . '#pgv-edit-' . (int) $v['id'];
								?>
								<a class="button-link" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Szerkesztés', 'pomodoro-gift-vouchers' ); ?></a>
								<a class="button-link" href="<?php echo esc_url( PGV_Admin::pdf_url( (int) $v['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'PDF', 'pomodoro-gift-vouchers' ); ?></a>
								<button type="button" class="button-link pgv-resend" data-id="<?php echo (int) $v['id']; ?>"><?php esc_html_e( 'E-mail újraküldés', 'pomodoro-gift-vouchers' ); ?></button>
								<button type="button" class="button-link pgv-toggle-audit" data-id="<?php echo (int) $v['id']; ?>"><?php esc_html_e( 'Előzmény', 'pomodoro-gift-vouchers' ); ?></button>
								<?php
								// Kód-csere: csak ott, ahol biztonságos (nem beváltott, nem importált).
								$pgv_can_regen = empty( $v['is_legacy'] )
									&& in_array( $v['status'], array( PGV_Vouchers::STATUS_ACTIVE, PGV_Vouchers::STATUS_PENDING ), true );
								?>
								<?php if ( $pgv_can_regen ) : ?>
									<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'Új kódot generálunk ennek az utalványnak (%s). A régi kód ezután érvénytelen, a már kiküldött PDF-en viszont az szerepel — a vásárlónak új levelet kell küldeni. A belső sorszám nem változik. Folytatod?', 'pomodoro-gift-vouchers' ), $v['serial'] ) ); ?>');">
										<?php wp_nonce_field( 'pgv_regen_serial' ); ?>
										<input type="hidden" name="pgv_action" value="regen_serial">
										<input type="hidden" name="voucher_id" value="<?php echo (int) $v['id']; ?>">
										<input type="hidden" name="ret_s" value="<?php echo esc_attr( $search ); ?>">
										<input type="hidden" name="ret_status" value="<?php echo esc_attr( $status ); ?>">
										<input type="hidden" name="ret_paged" value="<?php echo (int) $paged; ?>">
										<button type="submit" class="button-link"><?php esc_html_e( 'Új kód', 'pomodoro-gift-vouchers' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( $edit_id === (int) $v['id'] ) : ?>
							<tr class="pgv-edit-row" id="pgv-edit-<?php echo (int) $v['id']; ?>">
								<td colspan="8">
									<form method="post" class="pgv-edit-form">
										<?php wp_nonce_field( 'pgv_edit_voucher' ); ?>
										<input type="hidden" name="pgv_action" value="edit_voucher">
										<input type="hidden" name="voucher_id" value="<?php echo (int) $v['id']; ?>">
										<input type="hidden" name="ret_s" value="<?php echo esc_attr( $search ); ?>">
										<input type="hidden" name="ret_status" value="<?php echo esc_attr( $status ); ?>">
										<input type="hidden" name="ret_paged" value="<?php echo (int) $paged; ?>">
										<p class="description" style="margin:0 0 10px">
											<?php echo esc_html( sprintf( __( 'Szerkesztés — %s (a sorszám és az összeg nem módosítható).', 'pomodoro-gift-vouchers' ), $v['serial'] ) ); ?>
										</p>
										<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;max-width:820px">
											<label><?php esc_html_e( 'Megajándékozott neve', 'pomodoro-gift-vouchers' ); ?><br>
												<input type="text" name="recipient_name" class="regular-text" value="<?php echo esc_attr( $v['recipient_name'] ); ?>"></label>
											<label><?php esc_html_e( 'Ajándékozó / vásárló neve', 'pomodoro-gift-vouchers' ); ?><br>
												<input type="text" name="giver_name" class="regular-text" value="<?php echo esc_attr( $v['giver_name'] ); ?>"></label>
											<label style="grid-column:1 / -1"><?php esc_html_e( 'Üzenet a megajándékozottnak', 'pomodoro-gift-vouchers' ); ?><br>
												<textarea name="message" class="large-text" rows="3"><?php echo esc_textarea( $v['message'] ); ?></textarea></label>
											<label><?php esc_html_e( 'Kézbesítési e-mail', 'pomodoro-gift-vouchers' ); ?><br>
												<input type="email" name="delivery_email" class="regular-text" value="<?php echo esc_attr( $v['delivery_email'] ?: $v['buyer_email'] ); ?>"></label>
										</div>
										<p style="margin-top:12px">
											<label><input type="checkbox" name="resend" value="1"> <?php esc_html_e( 'Mentés után a javított PDF-et küldd is újra e-mailben', 'pomodoro-gift-vouchers' ); ?></label>
										</p>
										<p>
											<button type="submit" class="button button-primary"><?php esc_html_e( 'Mentés', 'pomodoro-gift-vouchers' ); ?></button>
											<a class="button" href="<?php echo esc_url( remove_query_arg( 'edit' ) ); ?>"><?php esc_html_e( 'Mégse', 'pomodoro-gift-vouchers' ); ?></a>
										</p>
									</form>
								</td>
							</tr>
						<?php endif; ?>
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
