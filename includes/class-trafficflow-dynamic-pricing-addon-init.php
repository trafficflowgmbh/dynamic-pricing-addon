<?php
/**
 * TrafficFlow Dynamic Pricing Addon Initialisation
 *
 * @package TrafficFlow_Dynamic_Pricing_Addon
 */

// Declare strict types.
declare( strict_types=1 );

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * TrafficFlow Dynamic Pricing Addon Initialisation.
 *
 * Handles plugin initialisation, dependency checks, and Polylang string registration.
 *
 * @since 1.0.0
 */
class TrafficFlow_Dynamic_Pricing_Addon_Init {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'tfdpa_register_polylang_strings' ), 20 );
	}

	/**
	 * Register all translatable strings with Polylang.
	 *
	 * This method extracts all translation strings from the plugin and registers
	 * them with Polylang for translation management.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tfdpa_register_polylang_strings(): void {
		// Only register if Polylang is active.
		if ( ! function_exists( 'pll_register_string' ) ) {
			return;
		}

		// Admin strings.
		pll_register_string( 'Admin UI', 'Discount Type:', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Admin UI', 'None', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Admin UI', 'Price Discount', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Admin UI', 'Percentage Discount', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Admin UI', 'Discount Value:', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Admin UI', 'Enter price to discount', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Admin UI', 'Enter percentage to discount', 'TrafficFlow Dynamic Pricing Addon' );

		// Frontend strings.
		pll_register_string( 'Frontend UI', 'Save', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', 'Save %s%%', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', 'Save %s', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', 'Discounted price', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', 'Original price', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', 'Savings', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', 'Out of Stock', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', 'Order Quantity', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', 'Unit Price', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', '(incl. VAT, plus %s)', 'TrafficFlow Dynamic Pricing Addon' );
		pll_register_string( 'Frontend UI', 'shipping costs', 'TrafficFlow Dynamic Pricing Addon' );
	}
}

// Initialise the init class.
new TrafficFlow_Dynamic_Pricing_Addon_Init();
