<?php
/**
 * Plugin Name: TrafficFlow Dynamic Pricing Addon
 * Description: Enables stores to offer role-based discounts when using the Dynamic Pricing for WooCommerce extension.
 * Version: 1.0.0
 * Author: TrafficFlow GmbH
 * Author URI: https://trafficflow.ch
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: trafficflow-dynamic-pricing-addon
 * Requires Plugins: woocommerce, woocommerce-dynamic-pricing
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.2
 * Tested up to: 6.8
 *
 * @package TrafficFlow_Dynamic_Pricing_Addon
 */

// Declare strict types.
declare( strict_types=1 );

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Define constants.
define( 'TRAFFICFLOW_DYNAMIC_PRICING_ADDON_FILE', __FILE__ );
define( 'TRAFFICFLOW_DYNAMIC_PRICING_ADDON_PATH', plugin_dir_path( __FILE__ ) );
define( 'TRAFFICFLOW_DYNAMIC_PRICING_ADDON_URL', plugin_dir_url( __FILE__ ) );
define( 'TRAFFICFLOW_DYNAMIC_PRICING_ADDON_VERSION', '1.0.0' );

// Declare compatibility with WooCommerce Custom Order Tables.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Remove the price hook from the child theme.
add_action( 'after_setup_theme', 'tfdpa_remove_child_theme_price_hook', 20 );
function tfdpa_remove_child_theme_price_hook() {
	remove_action( 'woocommerce_before_add_to_cart_form', 'woocommerce_total_product_price', 10 );
	remove_action( 'woocommerce_single_product_summary', 'show_pricing_rules', 27 );
	remove_action( 'blocksy:woocommerce:product-single:add_to_cart:after', 'show_pricing_rules', 27 );
}

// Include plugin classes.
require_once __DIR__ . '/includes/class-trafficflow-dynamic-pricing-addon-init.php';
require_once __DIR__ . '/includes/class-trafficflow-dynamic-pricing-addon-admin.php';
require_once __DIR__ . '/includes/class-trafficflow-dynamic-pricing-addon-frontend.php';
