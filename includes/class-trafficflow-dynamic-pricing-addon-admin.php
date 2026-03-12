<?php
/**
 * TrafficFlow Dynamic Pricing Addon Admin
 *
 * @package TrafficFlow_Dynamic_Pricing_Addon
 */

// Declare strict types.
declare( strict_types=1 );

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * TrafficFlow Dynamic Pricing Addon Admin.
 *
 * Handles all admin functionality including scripts, AJAX handlers, and admin interface.
 *
 * @since 1.0.0
 */
class TrafficFlow_Dynamic_Pricing_Addon_Admin {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'tfdpa_enqueue_admin_scripts' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'tfdpa_save_product_role_discounts' ) );
		add_action( 'woocommerce_product_options_pricing', array( $this, 'tfdpa_load_product_role_discounts' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'tfdpa_load_product_role_discounts' ), 999 );
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tfdpa_enqueue_admin_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		// Enqueue admin scripts.
		wp_enqueue_script(
			'tfdpa-admin-script',
			TRAFFICFLOW_DYNAMIC_PRICING_ADDON_URL . 'assets/js/admin.js',
			array(),
			TRAFFICFLOW_DYNAMIC_PRICING_ADDON_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'tfdpa-admin-script',
			'tfdpaL10n',
			array(
				'discountType'     => function_exists( 'pll__' ) ? pll__( 'Discount Type:' ) : 'Discount Type:',
				'none'             => function_exists( 'pll__' ) ? pll__( 'None' ) : 'None',
				'fixed'            => function_exists( 'pll__' ) ? pll__( 'Price Discount' ) : 'Price Discount',
				'percentage'       => function_exists( 'pll__' ) ? pll__( 'Percentage Discount' ) : 'Percentage Discount',
				'discountValue'    => function_exists( 'pll__' ) ? pll__( 'Discount Value:' ) : 'Discount Value:',
				'enterFixedAmount' => function_exists( 'pll__' ) ? pll__( 'Enter price to discount' ) : 'Enter price to discount',
				'enterPercentage'  => function_exists( 'pll__' ) ? pll__( 'Enter percentage to discount' ) : 'Enter percentage to discount',
			)
		);

		// Enqueue admin styles.
		wp_enqueue_style(
			'tfdpa-admin-style',
			TRAFFICFLOW_DYNAMIC_PRICING_ADDON_URL . 'assets/css/admin.css',
			array(),
			filemtime( TRAFFICFLOW_DYNAMIC_PRICING_ADDON_PATH . 'assets/css/admin.css' )
		);
	}

	/**
	 * Save role-based discount data when product is saved.
	 * Saves to the base language product so discounts stay synced across all translations.
	 *
	 * @since 1.0.0
	 * @param int $post_id The product post ID.
	 * @return void
	 */
	public function tfdpa_save_product_role_discounts( int $post_id ): void {
		// Verify nonce for security.
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		// Check user permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save to base language product so all translations stay in sync.
		$base_lang  = function_exists( 'theme_default_language' ) ? theme_default_language() : 'de';
		$save_to_id = $this->tfdpa_get_base_lang_post_id( $post_id, $base_lang );

		// Process discount type data.
		if ( isset( $_POST['trafficflow_discount_type'] ) && is_array( $_POST['trafficflow_discount_type'] ) ) {
			$discount_types  = array_map( 'sanitize_text_field', wp_unslash( $_POST['trafficflow_discount_type'] ) );
			$discount_values = isset( $_POST['trafficflow_discount_value'] ) && is_array( $_POST['trafficflow_discount_value'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['trafficflow_discount_value'] ) )
				: array();

			foreach ( $discount_types as $pricing_group_uid => $discount_type ) {
				$meta_key = '_trafficflow_role_discounts_' . $pricing_group_uid;

				// If discount type is 'none', remove the meta data.
				if ( 'none' === $discount_type ) {
					delete_post_meta( $save_to_id, $meta_key );
					continue;
				}

				// Get and validate discount value.
				$discount_value = isset( $discount_values[ $pricing_group_uid ] ) ? (float) $discount_values[ $pricing_group_uid ] : 0;

				// Validate percentage discounts don't exceed 100%.
				if ( 'percentage' === $discount_type && $discount_value > 100 ) {
					$discount_value = 100;
				}

				// Ensure discount value is not negative.
				if ( $discount_value < 0 ) {
					$discount_value = 0;
				}

				// Prepare discount data.
				$discount_data = array(
					'discount_type'  => $discount_type,
					'discount_value' => $discount_value,
				);

				// Only save if we have a valid discount value.
				if ( $discount_data['discount_value'] > 0 ) {
					update_post_meta( $save_to_id, $meta_key, wp_json_encode( $discount_data ) );
				} else {
					delete_post_meta( $save_to_id, $meta_key );
				}
			}
		}
	}

	/**
	 * Get the base language post ID for multilingual sync (Polylang).
	 *
	 * @since 1.0.0
	 * @param int    $post_id   The post ID.
	 * @param string $base_lang Base language code (default 'de').
	 * @return int The base language post ID.
	 */
	private function tfdpa_get_base_lang_post_id( int $post_id, string $base_lang = 'de' ): int {
		if ( $post_id <= 0 ) {
			return $post_id;
		}
		if ( function_exists( 'pll_get_post' ) ) {
			$base_id = pll_get_post( $post_id, $base_lang );
			return ( $base_id && $base_id > 0 ) ? (int) $base_id : $post_id;
		}
		return $post_id;
	}

	/**
	 * Load and output existing role-based discount data for JavaScript.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tfdpa_load_product_role_discounts(): void {
		global $post;

		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		// Avoid adding inline script twice (we hook to both pricing and data panels).
		static $loaded = false;
		if ( $loaded ) {
			return;
		}

		// Load discount meta from base language product so translations display the same values.
		$base_lang = function_exists( 'theme_default_language' ) ? theme_default_language() : 'de';
		$load_from_id = $this->tfdpa_get_base_lang_post_id( (int) $post->ID, $base_lang );
		$meta_keys    = get_post_meta( $load_from_id );
		$role_discounts = array();

		foreach ( $meta_keys as $meta_key => $meta_values ) {
			if ( strpos( $meta_key, '_trafficflow_role_discounts_' ) === 0 && isset( $meta_values[0] ) ) {
				$pricing_group_uid = str_replace( '_trafficflow_role_discounts_', '', $meta_key );
				$discount_data     = json_decode( $meta_values[0], true );

				if ( $discount_data ) {
					$role_discounts[ $pricing_group_uid ] = $discount_data;
				}
			}
		}

		// Always output so admin.js can read it (empty object when no discounts).
		$loaded = true;
		wp_add_inline_script(
			'tfdpa-admin-script',
			'window.tfdpaExistingDiscounts = ' . wp_json_encode( $role_discounts ) . ';',
			'before'
		);
	}
}

// Initialise the admin class.
new TrafficFlow_Dynamic_Pricing_Addon_Admin();
