<?php
/**
 * Beállítások nézet.
 *
 * @package Pomodoro_Gift_Vouchers
 * @var PGV_Admin $this
 */

defined( 'ABSPATH' ) || exit;

$s     = PGV_Settings::all();
$units = array(
	'casa'      => "Casa Pomo d'Oro",
	'osteria'   => "Osteria Pomo d'Oro",
	'pizzabar'  => "Pizzabar Pomo d'Oro",
	'trattoria' => "Trattoria Pomo d'Oro",
);
$api_key = get_option( 'pgv_api_key' );
?>
<div class="wrap pgv-wrap">
	<h1 class="pgv-title"><?php esc_html_e( 'Beállítások', 'pomodoro-gift-vouchers' ); ?></h1>

	<form method="post" class="pgv-card">
		<?php wp_nonce_field( 'pgv_settings' ); ?>
		<input type="hidden" name="pgv_action" value="save_settings">

		<h2><?php esc_html_e( 'Egység', 'pomodoro-gift-vouchers' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="unit_slug"><?php esc_html_e( 'Egység', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td>
					<select name="unit_slug" id="unit_slug">
						<?php foreach ( $units as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['unit_slug'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Egy store = egy egység. A négy egység külön WP-oldalon fut.', 'pomodoro-gift-vouchers' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="unit_name"><?php esc_html_e( 'Egység neve', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td><input type="text" name="unit_name" id="unit_name" class="regular-text" value="<?php echo esc_attr( $s['unit_name'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="serial_prefix"><?php esc_html_e( 'Sorszám-előtag', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td>
					<input type="text" name="serial_prefix" id="serial_prefix" value="<?php echo esc_attr( $s['serial_prefix'] ); ?>">
					<p class="description"><?php echo esc_html( sprintf( __( 'Pl. %s → %s-2026-000042', 'pomodoro-gift-vouchers' ), $s['serial_prefix'], $s['serial_prefix'] ) ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="company_name"><?php esc_html_e( 'Cégnév', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td><input type="text" name="company_name" id="company_name" class="regular-text" value="<?php echo esc_attr( $s['company_name'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="tax_number"><?php esc_html_e( 'Adószám', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td><input type="text" name="tax_number" id="tax_number" class="regular-text" value="<?php echo esc_attr( $s['tax_number'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="validity_months"><?php esc_html_e( 'Érvényesség (hónap)', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td><input type="number" name="validity_months" id="validity_months" min="1" value="<?php echo esc_attr( $s['validity_months'] ); ?>"> <span class="description"><?php esc_html_e( 'A vásárlástól számítva.', 'pomodoro-gift-vouchers' ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Aktív', 'pomodoro-gift-vouchers' ); ?></th>
				<td><label><input type="checkbox" name="active" value="1" <?php checked( $s['active'], 1 ); ?>> <?php esc_html_e( 'Az egység értékesít utalványt', 'pomodoro-gift-vouchers' ); ?></label></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Kézbesítés / e-mail', 'pomodoro-gift-vouchers' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="from_name"><?php esc_html_e( 'Feladó neve', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td><input type="text" name="from_name" id="from_name" class="regular-text" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="from_email"><?php esc_html_e( 'Feladó e-mail', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td>
					<input type="email" name="from_email" id="from_email" class="regular-text" value="<?php echo esc_attr( $s['from_email'] ); ?>">
					<p class="description"><?php esc_html_e( 'Saját domainről, SPF/DKIM/DMARC beállításokkal a kézbesíthetőségért.', 'pomodoro-gift-vouchers' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="delivery_default"><?php esc_html_e( 'Alap kézbesítés', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td>
					<select name="delivery_default" id="delivery_default">
						<option value="recipient" <?php selected( $s['delivery_default'], 'recipient' ); ?>><?php esc_html_e( 'A megajándékozottnak', 'pomodoro-gift-vouchers' ); ?></option>
						<option value="buyer" <?php selected( $s['delivery_default'], 'buyer' ); ?>><?php esc_html_e( 'A vásárlónak', 'pomodoro-gift-vouchers' ); ?></option>
					</select>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Marketing / céges figyelmeztetés', 'pomodoro-gift-vouchers' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="marketing_label"><?php esc_html_e( 'Marketing felirat', 'pomodoro-gift-vouchers' ); ?></label></th>
				<td><input type="text" name="marketing_label" id="marketing_label" class="large-text" value="<?php echo esc_attr( $s['marketing_label'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Cégnév/adószám figyelmeztetés', 'pomodoro-gift-vouchers' ); ?></th>
				<td>
					<label><input type="checkbox" name="corporate_warn" value="1" <?php checked( $s['corporate_warn'], 1 ); ?>> <?php esc_html_e( 'Élő figyelmeztetés a checkouton (áfás számla nem igényelhető)', 'pomodoro-gift-vouchers' ); ?></label><br>
					<label><input type="checkbox" name="corporate_block" value="1" <?php checked( $s['corporate_block'], 1 ); ?>> <?php esc_html_e( 'Céges adat esetén a fizetés blokkolása (opcionális, szigorúbb)', 'pomodoro-gift-vouchers' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Hézagmentes sorszám', 'pomodoro-gift-vouchers' ); ?></th>
				<td><label><input type="checkbox" name="gapless_serial" value="1" <?php checked( $s['gapless_serial'], 1 ); ?>> <?php esc_html_e( 'Hézagmentes, per-egység + év sorszám', 'pomodoro-gift-vouchers' ); ?></label></td>
			</tr>
		</table>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Mentés', 'pomodoro-gift-vouchers' ); ?></button></p>
	</form>

	<div class="pgv-card">
		<h2><?php esc_html_e( 'CRM olvasó API', 'pomodoro-gift-vouchers' ); ?></h2>
		<p><?php esc_html_e( 'Végpontok:', 'pomodoro-gift-vouchers' ); ?></p>
		<code><?php echo esc_html( rest_url( PGV_REST::NS . '/vouchers' ) ); ?></code><br>
		<code><?php echo esc_html( rest_url( PGV_REST::NS . '/orders' ) ); ?></code><br>
		<code><?php echo esc_html( rest_url( PGV_REST::NS . '/customers' ) ); ?></code>
		<p class="description"><?php esc_html_e( 'Hitelesítés: x-api-key fejléc (vagy Authorization: Bearer, illetve ?api_key=). A CRM a WooCommerce beépített REST-jét is használhatja.', 'pomodoro-gift-vouchers' ); ?></p>
		<?php if ( $api_key ) : ?>
			<p><strong><?php esc_html_e( 'API kulcs (csak most látszik, mentsd el!):', 'pomodoro-gift-vouchers' ); ?></strong><br>
			<code><?php echo esc_html( $api_key ); ?></code></p>
			<p class="description"><?php esc_html_e( 'Biztonsági okból a nyers kulcsot csak a hash-eléséig tároljuk. Miután elmentetted, a preview alatt már csak a hash marad.', 'pomodoro-gift-vouchers' ); ?></p>
		<?php else : ?>
			<p class="description"><?php echo esc_html( sprintf( __( 'Kulcs-előnézet: %s', 'pomodoro-gift-vouchers' ), get_option( 'pgv_api_key_preview', '—' ) ) ); ?></p>
		<?php endif; ?>
	</div>
</div>
