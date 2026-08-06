<?php
/**
 * CRM olvasó REST API — a supabase/CRM_API.md-t tükrözi, de a WooCommerce-táblákra.
 *
 * A CRM a WooCommerce beépített orders/customers REST-jét is használhatja; ez a
 * végpont az egyedi utalvány-adatot (sorszám, státusz, beváltás, marketing) adja,
 * API-kulcsos hitelesítéssel, inkrementális szinkronnal.
 *
 * Base: /wp-json/pgv/v1/{vouchers|orders|customers}
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_REST {

	const NS = 'pgv/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$args = array(
			'permission_callback' => array( $this, 'check_key' ),
			'methods'             => WP_REST_Server::READABLE,
		);

		register_rest_route( self::NS, '/vouchers', array_merge( $args, array( 'callback' => array( $this, 'get_vouchers' ) ) ) );
		register_rest_route( self::NS, '/orders', array_merge( $args, array( 'callback' => array( $this, 'get_orders' ) ) ) );
		register_rest_route( self::NS, '/customers', array_merge( $args, array( 'callback' => array( $this, 'get_customers' ) ) ) );
	}

	/**
	 * API-kulcs ellenőrzés (fejléc vagy query).
	 */
	public function check_key( WP_REST_Request $request ) {
		$key = $request->get_header( 'x-api-key' );
		if ( ! $key ) {
			$auth = $request->get_header( 'authorization' );
			if ( $auth && preg_match( '/Bearer\s+(.+)/i', $auth, $m ) ) {
				$key = trim( $m[1] );
			}
		}
		if ( ! $key ) {
			$key = $request->get_param( 'api_key' );
		}
		if ( ! $key ) {
			return new WP_Error( 'pgv_no_key', __( 'Hiányzó API kulcs', 'pomodoro-gift-vouchers' ), array( 'status' => 401 ) );
		}

		$stored = get_option( 'pgv_api_key_hash' );
		if ( ! $stored || ! hash_equals( $stored, hash( 'sha256', $key ) ) ) {
			return new WP_Error( 'pgv_bad_key', __( 'Érvénytelen API kulcs', 'pomodoro-gift-vouchers' ), array( 'status' => 401 ) );
		}
		update_option( 'pgv_api_key_last_used', current_time( 'mysql' ), false );
		return true;
	}

	private function limit( $request ) {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 100;
		}
		return min( 500, $limit );
	}

	/**
	 * GET /vouchers — lapozott, updated_since / cursor szűréssel.
	 */
	public function get_vouchers( WP_REST_Request $request ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();
		$limit = $this->limit( $request );

		$where  = array( 'unit_slug = %s' );
		$params = array( $unit );

		$since  = $request->get_param( 'updated_since' );
		$cursor = $request->get_param( 'cursor' );
		$point  = $cursor ?: $since;
		if ( $point ) {
			$where[]  = 'updated_at > %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( $point ) );
		}

		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at ASC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $limit ) ) ), ARRAY_A ); // phpcs:ignore

		$data = array();
		foreach ( (array) $rows as $r ) {
			$data[] = array(
				'serial'         => $r['serial'],
				'unit'           => $r['unit_slug'],
				'amount'         => (int) $r['amount'],
				'status'         => $r['status'],
				'giver_name'     => $r['giver_name'],
				'recipient_name' => $r['recipient_name'],
				'delivery_email' => $r['delivery_email'],
				'buyer_email'    => $r['buyer_email'],
				'marketing_opt_in' => (bool) $r['marketing_opt_in'],
				'valid_from'     => $r['valid_from'],
				'valid_until'    => $r['valid_until'],
				'redeemed_at'    => $r['redeemed_at'],
				'is_legacy'      => (bool) $r['is_legacy'],
				'created_at'     => $r['created_at'],
				'updated_at'     => $r['updated_at'],
			);
		}
		$next = ( count( $data ) === $limit ) ? end( $data )['updated_at'] : null;
		return rest_ensure_response( array( 'data' => $data, 'next_cursor' => $next ) );
	}

	/**
	 * GET /orders — utalványokból aggregálva rendelésenként.
	 */
	public function get_orders( WP_REST_Request $request ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();
		$limit = $this->limit( $request );

		$where  = array( 'unit_slug = %s', 'order_id IS NOT NULL' );
		$params = array( $unit );

		$point = $request->get_param( 'cursor' ) ?: $request->get_param( 'updated_since' );
		if ( $point ) {
			$where[]  = 'updated_at > %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( $point ) );
		}

		$sql = "SELECT order_id,
			MIN(buyer_email) buyer_email,
			MIN(giver_name) giver_name,
			SUM(amount) amount,
			MAX(marketing_opt_in) marketing_opt_in,
			MIN(paid_at) paid_at,
			MIN(created_at) created_at,
			MAX(updated_at) updated_at
			FROM {$table} WHERE " . implode( ' AND ', $where ) . '
			GROUP BY order_id ORDER BY updated_at ASC LIMIT %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $limit ) ) ), ARRAY_A ); // phpcs:ignore

		$data = array();
		foreach ( (array) $rows as $r ) {
			$order = wc_get_order( (int) $r['order_id'] );
			$data[] = array(
				'order_ref'        => $order ? $order->get_order_number() : (string) $r['order_id'],
				'unit'             => $unit,
				'buyer_name'       => $r['giver_name'],
				'buyer_email'      => $r['buyer_email'],
				'amount'           => (int) $r['amount'],
				'status'           => $order ? $order->get_status() : 'unknown',
				'marketing_opt_in' => (bool) $r['marketing_opt_in'],
				'paid_at'          => $r['paid_at'],
				'created_at'       => $r['created_at'],
				'updated_at'       => $r['updated_at'],
			);
		}
		$next = ( count( $data ) === $limit ) ? end( $data )['updated_at'] : null;
		return rest_ensure_response( array( 'data' => $data, 'next_cursor' => $next ) );
	}

	/**
	 * GET /customers — vevők e-mail szerint aggregálva (szegmentáláshoz).
	 */
	public function get_customers( WP_REST_Request $request ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();

		$where  = array( 'unit_slug = %s', "buyer_email <> ''" );
		$params = array( $unit );

		$since = $request->get_param( 'updated_since' );
		if ( $since ) {
			$where[]  = 'updated_at > %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( $since ) );
		}

		$sql = "SELECT buyer_email email,
			MIN(giver_name) name,
			COUNT(DISTINCT order_id) orders,
			SUM(amount) total_spent,
			MAX(marketing_opt_in) marketing_opt_in,
			MIN(created_at) first_purchase,
			MAX(created_at) last_purchase
			FROM {$table} WHERE " . implode( ' AND ', $where ) . '
			GROUP BY buyer_email ORDER BY last_purchase DESC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore

		$data = array();
		foreach ( (array) $rows as $r ) {
			$data[] = array(
				'email'            => $r['email'],
				'name'             => $r['name'],
				'orders'           => (int) $r['orders'],
				'total_spent'      => (int) $r['total_spent'],
				'units'            => array( $unit ),
				'marketing_opt_in' => (bool) $r['marketing_opt_in'],
				'first_purchase'   => $r['first_purchase'],
				'last_purchase'    => $r['last_purchase'],
			);
		}
		return rest_ensure_response( array( 'data' => $data ) );
	}
}
