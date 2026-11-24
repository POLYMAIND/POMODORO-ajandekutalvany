<?php
/**
 * Képkészlet nézet — elnevezett képek egységenként.
 *
 * @package Pomodoro_Gift_Vouchers
 * @var PGV_Admin $this
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$table = PGV_Install::table( 'images' );
$unit  = PGV_Settings::unit_slug();
$images = $wpdb->get_results(
	$wpdb->prepare( "SELECT * FROM {$table} WHERE unit_slug = %s ORDER BY sort_order ASC, id ASC", $unit ), // phpcs:ignore
	ARRAY_A
);
?>
<div class="wrap pgv-wrap">
	<h1 class="pgv-title"><?php esc_html_e( 'Képkészlet', 'pomodoro-gift-vouchers' ); ?> — <?php echo esc_html( PGV_Settings::get( 'unit_name' ) ); ?></h1>

	<div class="pgv-card">
		<h2><?php esc_html_e( 'Új kép hozzáadása', 'pomodoro-gift-vouchers' ); ?></h2>
		<form method="post" id="pgv-add-image-form">
			<?php wp_nonce_field( 'pgv_images' ); ?>
			<input type="hidden" name="pgv_action" value="add_image">
			<input type="hidden" name="attachment_id" id="pgv_attachment_id" value="">
			<p>
				<button type="button" class="button" id="pgv-pick-image"><?php esc_html_e( 'Kép kiválasztása a médiatárból', 'pomodoro-gift-vouchers' ); ?></button>
				<span id="pgv-picked-preview"></span>
			</p>
			<p>
				<label><?php esc_html_e( 'Kép neve', 'pomodoro-gift-vouchers' ); ?><br>
				<input type="text" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'pl. Klasszikus, Ünnepi, Születésnap', 'pomodoro-gift-vouchers' ); ?>"></label>
			</p>
			<p><button type="submit" class="button button-primary" id="pgv-add-image-submit" disabled><?php esc_html_e( 'Hozzáadás', 'pomodoro-gift-vouchers' ); ?></button></p>
		</form>
	</div>

	<div class="pgv-card">
		<h2><?php esc_html_e( 'Készlet', 'pomodoro-gift-vouchers' ); ?></h2>
		<?php if ( empty( $images ) ) : ?>
			<p><?php esc_html_e( 'Még nincs kép a készletben.', 'pomodoro-gift-vouchers' ); ?></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'pgv_images' ); ?>
				<input type="hidden" name="pgv_action" value="update_images">
				<div class="pgv-image-grid">
					<?php foreach ( $images as $img ) : ?>
						<div class="pgv-image-cell">
							<?php echo wp_get_attachment_image( (int) $img['attachment_id'], 'medium' ); // phpcs:ignore ?>
							<input type="text" name="image_title[<?php echo (int) $img['id']; ?>]" value="<?php echo esc_attr( $img['title'] ); ?>" class="widefat">
							<label><input type="checkbox" name="image_active[]" value="<?php echo (int) $img['id']; ?>" <?php checked( (int) $img['active'], 1 ); ?>> <?php esc_html_e( 'Aktív', 'pomodoro-gift-vouchers' ); ?></label>
						</div>
					<?php endforeach; ?>
				</div>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Módosítások mentése', 'pomodoro-gift-vouchers' ); ?></button></p>
			</form>
		<?php endif; ?>
	</div>
</div>
