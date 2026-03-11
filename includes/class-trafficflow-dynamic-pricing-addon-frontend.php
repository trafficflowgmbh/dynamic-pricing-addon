<?php
/**
 * TrafficFlow Dynamic Pricing Addon
 *
 * @package TrafficFlow_Dynamic_Pricing_Addon
 */

// Declare strict types.
declare(strict_types=1);

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * TrafficFlow Dynamic Pricing Addon Frontend.
 *
 * Handles frontend discount application and display.
 *
 * @since 1.0.0
 */
class TrafficFlow_Dynamic_Pricing_Addon_Frontend {
	private $is_applying_pricing = false;
	private $is_in_cart_context  = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct() {
		// Simple product and variable product main hooks
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'tfdpa_filter_regular_price' ), 9999, 2 );
		add_filter( 'woocommerce_product_get_price', array( $this, 'tfdpa_filter_price' ), 9999, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'tfdpa_filter_price_html' ), 9999, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'tfdpa_cart_item_price' ), 9999, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'tfdpa_cart_item_subtotal' ), 9999, 3 );

		// Variable product variation-specific hooks
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'tfdpa_filter_variation_regular_price' ), 9999, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'tfdpa_filter_variation_price' ), 9999, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'tfdpa_filter_variation_sale_price' ), 9999, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'tfdpa_filter_variation_prices_array' ), 9999, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'tfdpa_filter_variation_prices_array' ), 9999, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'tfdpa_filter_variation_prices_array_sale' ), 9999, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'tfdpa_prices_hash_by_role' ), 9999, 3 );

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'tfdpa_adjust_cart_item_prices' ), 999 );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'tfdpa_recalculate_cart_totals' ), 999 );
		add_filter( 'woocommerce_cart_get_total', array( $this, 'tfdpa_filter_cart_total' ), 999, 1 );

		// Remove the total product price hook from the blocksy theme.
		add_action(
			'init',
			function () {
				remove_action( 'blocksy:woocommerce:product-single:add_to_cart:before', 'woocommerce_total_product_price', 10 );
			},
			20
		);

		// Adjust pricing table on product page
		add_action( 'blocksy:woocommerce:product-single:add_to_cart:after', array( $this, 'show_pricing_rules' ), 15 );

		// Only initialize on frontend.
		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'tfdpa_enqueue_assets' ) );
			// add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'tfdpa_generate_trafficflow_price_html' ) );
			add_action( 'blocksy:woocommerce:product-single:add_to_cart:before', array( $this, 'tfdpa_generate_trafficflow_price_html' ), 5 );
		}

		// Debugging output
		// add_action( 'woocommerce_review_order_after_order_total', [ $this, 'tfdpa_debug_output_hidden' ], 999 );
	}

	public function tfdpa_debug_output_hidden() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		echo '<div style="position:fixed; top:35px; left:3px; background:black; color:lime; padding:10px; z-index:99999; font-family:monospace; font-size:11px; max-height:90vh; overflow-y:auto;">';
		echo '<strong>DEBUG INFO:</strong><br>';
		echo 'Subtotal: ' . wc_price( $cart->get_subtotal() ) . '<br>';
		echo 'Shipping: ' . wc_price( $cart->get_shipping_total() ) . '<br>';
		echo 'Cart item quantities: ' . print_r(
			array_map(
				function ( $item ) {
					return $item['quantity'];
				},
				$cart->get_cart()
			),
			true
		) . '<br>';
		echo 'Total (edit): ' . $cart->get_total( 'edit' ) . '<br>';
		echo 'Total (display): ' . $cart->get_total( 'view' ) . '<br>';
		echo 'Chosen shipping: ' . print_r( WC()->session->get( 'chosen_shipping_methods' ), true ) . '<br>';
		echo 'POST shipping_method: ' . print_r( $_POST['shipping_method'] ?? 'NOT SET', true ) . '<br>';

		// Show all available shipping for package 0
		$packages = WC()->shipping()->get_packages();
		if ( isset( $packages[0]['rates'] ) ) {
			echo 'Available rates: ' . print_r( array_keys( $packages[0]['rates'] ), true ) . '<br>';
		}

		echo '</div>';
	}

	/**
	 * Filter the cart total to ensure correct calculation.
	 *
	 * @since 1.0.0
	 * @param string $total The cart total.
	 * @return string The filtered cart total.
	 */
	public function tfdpa_filter_cart_total( $total ) {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return $total;
		}

		// Calculate the correct total
		$calculated_total = $cart->get_subtotal()
						+ $cart->get_subtotal_tax()
						+ $cart->get_shipping_total()
						+ $cart->get_shipping_tax()
						- $cart->get_discount_total()
						- $cart->get_discount_tax();

		return $calculated_total;
	}

	/**
	 * Filter cart item price display.
	 *
	 * @since 1.0.0
	 * @param string $price The original price HTML.
	 * @param array  $cart_item The cart item data.
	 * @param string $cart_item_key The cart item key.
	 * @return string The filtered price HTML.
	 */
	public function tfdpa_cart_item_price( $price, $cart_item, $cart_item_key ) {
		// Check if we have TrafficFlow pricing data
		if ( isset( $cart_item['trafficflow_price'] ) && isset( $cart_item['trafficflow_original_price'] ) ) {
			$trafficflow_price = (float) $cart_item['trafficflow_price'];
			$original_price    = (float) $cart_item['trafficflow_original_price'];

			// If there's a discount, show both prices
			if ( $trafficflow_price < $original_price ) {
				return '<span class="trafficflow-original"><s>' . wc_price( $original_price ) . '</s></span> <strong>' . wc_price( $trafficflow_price ) . '</strong>';
			} else {
				return '<strong>' . wc_price( $trafficflow_price ) . '</strong>';
			}
		}

		return $price;
	}

	/**
	 * Filter cart item subtotal display.
	 *
	 * @since 1.0.0
	 * @param string $subtotal The original subtotal HTML.
	 * @param array  $cart_item The cart item data.
	 * @param string $cart_item_key The cart item key.
	 * @return string The filtered subtotal HTML.
	 */
	public function tfdpa_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {
		// Check if we have TrafficFlow pricing data
		if ( isset( $cart_item['trafficflow_price'] ) ) {
			$trafficflow_price = (float) $cart_item['trafficflow_price'];
			$quantity          = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
			$total             = $trafficflow_price * $quantity;

			return wc_price( $total );
		}

		return $subtotal;
	}

	/**
	 * Normalizes a string for variation name matching (matches JS normalizeChars).
	 *
	 * Converts accented and special characters to their ASCII equivalents,
	 * trims whitespace, and transforms the string to lowercase for consistent matching.
	 *
	 * @since 1.0.0
	 * @param string $str The input string to normalize.
	 * @return string The normalized string.
	 */
	private function tfdpa_normalize_chars( string $str ): string {
		$str = strtolower( $str );
		$str = str_replace( array( '-', '.', "'" ), ' ', $str );
		$str = str_replace( array( 'ü' ), 'ue', $str );
		$str = str_replace( array( 'ö' ), 'oe', $str );
		$str = str_replace( array( 'ä' ), 'ae', $str );
		$str = preg_replace( '/[éèê]/u', 'e', $str );
		$str = preg_replace( '/[àâ]/u', 'a', $str );
		$str = preg_replace( '/[ïî]/u', 'i', $str );
		$str = preg_replace( '/[ôö]/u', 'o', $str );
		$str = preg_replace( '/[ùû]/u', 'u', $str );
		$str = str_replace( array( 'ç' ), 'c', $str );
		$str = preg_replace( '/[^a-z0-9\s]/u', '', $str );
		$str = preg_replace( '/\s+/', ' ', $str );
		return trim( $str );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tfdpa_enqueue_assets(): void {
		wp_enqueue_style(
			'tfdpa-frontend-style',
			TRAFFICFLOW_DYNAMIC_PRICING_ADDON_URL . 'assets/css/frontend.css',
			array(),
			filemtime( TRAFFICFLOW_DYNAMIC_PRICING_ADDON_PATH . 'assets/css/frontend.css' )
		);

		wp_enqueue_script(
			'tfdpa-frontend-script',
			TRAFFICFLOW_DYNAMIC_PRICING_ADDON_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			filemtime( TRAFFICFLOW_DYNAMIC_PRICING_ADDON_PATH . 'assets/js/frontend.js' ),
			true
		);

		wp_localize_script(
			'tfdpa-frontend-script',
			'tfdpaL10n',
			array(
				'save' => function_exists( 'pll__' ) ? pll__( 'Save' ) : 'Save',
			)
		);

		global $product;
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! is_product() ) {
			return;
		}

		$user_roles = $this->tfdpa_get_current_user_roles();
		if ( $product->is_type( 'variable' ) ) {
			$variation_discounts = array();
			$variations          = $product->get_children();

			// Map each variation's normalized attributes to its discount data.
			foreach ( $variations as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation && $variation->is_type( 'variation' ) ) {
					// Get only the variation attributes (not the full product name)
					$attributes                              = $variation->get_attributes();
					$variation_name                          = implode( ' ', array_values( $attributes ) );
					$normalized_name                         = $this->tfdpa_normalize_chars( $variation_name );
					$variation_discounts[ $normalized_name ] = $this->tfdpa_get_trafficflow_discount_data( $variation );
				}
			}

			wp_localize_script(
				'tfdpa-frontend-script',
				'trafficflow_discount_data',
				array(
					'discounts'   => $variation_discounts,
					'user_roles'  => $user_roles,
					'is_variable' => true,
				)
			);
		} else {
			$trafficflow_discounts = $this->tfdpa_get_trafficflow_discount_data( $product );
			$savings_display       = $this->tfdpa_get_savings_display( $product );

			wp_localize_script(
				'tfdpa-frontend-script',
				'trafficflow_discount_data',
				array(
					'discounts'       => $trafficflow_discounts,
					'user_roles'      => $user_roles,
					'savings_display' => $savings_display,
					'is_variable'     => false,
				)
			);
		}
	}

	/**
	 * Modify the variation prices hash to include user roles for caching.
	 *
	 * This ensures that price caches are role-specific, preventing users from
	 * seeing cached prices from other user roles.
	 *
	 * @since 1.0.0
	 * @param array       $hash        The existing price hash array.
	 * @param \WC_Product $product     The product object.
	 * @param bool        $for_display Whether the price is for display purposes.
	 * @return array The modified hash array with user roles included.
	 */
	public function tfdpa_prices_hash_by_role( $hash, $product, $for_display ) {
		$roles = $this->tfdpa_get_current_user_roles();
		sort( $roles );
		$hash['tfdpa_roles'] = implode( '|', $roles );
		$hash['tfdpa_user']  = (string) get_current_user_id();
		$hash['tfdpa_qty']   = '1';
		return $hash;
	}

	/**
	 * Filter variation prices array to apply role-based discounts.
	 *
	 * This method is called by WooCommerce when building the variation prices array.
	 * It applies TrafficFlow role-based discounts to variation prices while
	 * preventing infinite recursion through the pricing system.
	 *
	 * @since 1.0.0
	 * @param float|string          $price     The original variation price.
	 * @param \WC_Product_Variation $variation The variation product object.
	 * @param \WC_Product           $product   The parent product object.
	 * @return float The discounted variation price.
	 */
	public function tfdpa_filter_variation_prices_array( $price, $variation, $product ) {
		// Prevent infinite recursion.
		if ( $this->is_applying_pricing ) {
			return (float) $price;
		}

		$this->is_applying_pricing = true;
		$final                     = $this->tfdpa_get_role_based_discounted_price( $variation );
		$this->is_applying_pricing = false;

		return (float) $final;
	}

	/**
	 * Filter variation sale prices array.
	 *
	 * This method is called by WooCommerce for sale prices in the variation
	 * prices array. Currently returns the original price without modification.
	 *
	 * @since 1.0.0
	 * @param float|string          $price     The original variation sale price.
	 * @param \WC_Product_Variation $variation The variation product object.
	 * @param \WC_Product           $product   The parent product object.
	 * @return float The filtered variation sale price.
	 */
	public function tfdpa_filter_variation_prices_array_sale( $price, $variation, $product ) {
		return (float) $price;
	}

	/**
	 * Filter the regular price to prevent recursion.
	 *
	 * Uses unified candidate selection to ensure role-scoped base price consistency.
	 *
	 * @since 1.0.0
	 * @param float|string $price The original price.
	 * @param \WC_Product  $product The product object.
	 * @return float The filtered price.
	 */
	public function tfdpa_filter_regular_price( $price, $product ) {
		// Skip filtering if we're in cart context or already applying pricing
		if ( $this->is_applying_pricing || $this->is_in_cart_context ) {
			return (float) $price;
		}

		$this->is_applying_pricing = true;

		// Use unified candidate selection to get role-scoped base price
		$candidate = $this->tfdpa_get_best_role_scoped_candidate( $product, 1 );
		if ( $candidate && isset( $candidate['base'] ) && $candidate['base'] > 0 ) {
			$filtered_price = (float) $candidate['base'];
		} else {
			// Fallback to original behavior
			$filtered_price = $this->tfdpa_get_dynamic_pricing_product_price( $product );
		}

		$this->is_applying_pricing = false;

		return $filtered_price;
	}

	/**
	 * Filter the price to prevent recursion.
	 *
	 * @since 1.0.0
	 * @param float|string $price The original price.
	 * @param \WC_Product  $product The product object.
	 * @return float The filtered price.
	 */
	public function tfdpa_filter_price( $price, $product ) {
		// Skip filtering if we're in cart context or already applying pricing
		if ( $this->is_applying_pricing || $this->is_in_cart_context ) {
			return (float) $price;
		}

		$this->is_applying_pricing = true;
		$filtered_price            = $this->tfdpa_get_role_based_discounted_price( $product );
		$this->is_applying_pricing = false;

		return $filtered_price;
	}

	/**
	 * Filter the regular price for product variations to prevent recursion.
	 *
	 * Uses unified candidate selection to ensure role-scoped base price consistency.
	 *
	 * @since 1.0.0
	 * @param float|string          $price The original price.
	 * @param \WC_Product_Variation $variation The product variation object.
	 * @return float The filtered price.
	 */
	public function tfdpa_filter_variation_regular_price( $price, $variation ) {
		// Skip filtering if we're in cart context or already applying pricing
		if ( $this->is_applying_pricing || $this->is_in_cart_context ) {
			return (float) $price;
		}

		$this->is_applying_pricing = true;

		// Use unified candidate selection to get role-scoped base price
		$candidate = $this->tfdpa_get_best_role_scoped_candidate( $variation, 1 );
		if ( $candidate && isset( $candidate['base'] ) && $candidate['base'] > 0 ) {
			$filtered_price = (float) $candidate['base'];
		} else {
			// Fallback to original behavior
			$filtered_price = $this->tfdpa_get_dynamic_pricing_product_price( $variation );
		}

		$this->is_applying_pricing = false;

		return $filtered_price;
	}

	/**
	 * Filter the price for product variations to prevent recursion.
	 *
	 * @since 1.0.0
	 * @param float|string          $price The original price.
	 * @param \WC_Product_Variation $variation The product variation object.
	 * @return float The filtered price.
	 */
	public function tfdpa_filter_variation_price( $price, $variation ) {
		// Skip filtering if we're in cart context or already applying pricing
		if ( $this->is_applying_pricing || $this->is_in_cart_context ) {
			return (float) $price;
		}

		$this->is_applying_pricing = true;
		$filtered                  = $this->tfdpa_get_role_based_discounted_price( $variation );
		$this->is_applying_pricing = false;
		return $filtered;
	}

	/**
	 * Filter the sale price for product variations to prevent recursion.
	 *
	 * @since 1.0.0
	 * @param float|string          $price The original sale price.
	 * @param \WC_Product_Variation $variation The product variation object.
	 * @return float The filtered sale price.
	 */
	public function tfdpa_filter_variation_sale_price( $price, $variation ) {
		return (float) $price;
	}

	/**
	 * Generate traffic flow price HTML with strikethrough dynamic price.
	 *
	 * @since 1.0.0
	 * @return string The formatted price HTML.
	 */
	public function tfdpa_generate_trafficflow_price_html(): void {
		global $product;

		if ( empty( $product ) || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		// If product is variable, output a container for JS-driven price updates
		if ( $product->is_type( 'variable' ) ) {
			$this->tfdpa_generate_variable_product_price_html( $product );
			return;
		}

		// For simple products, calculate and display the price and discount directly in PHP
		$current_product = $product;
		$candidate       = $this->tfdpa_get_best_role_scoped_candidate( $current_product, 1 );

		$dynamic_price     = $candidate ? (float) $candidate['base'] : $this->tfdpa_get_dynamic_pricing_product_price( $current_product );
		$trafficflow_price = $candidate ? (float) $candidate['final'] : $this->tfdpa_get_role_based_discounted_price( $current_product );

		if ( ! is_numeric( $dynamic_price ) || ! is_numeric( $trafficflow_price ) || $dynamic_price < 0 || $trafficflow_price < 0 ) {
			return;
		}

		$has_discount = $trafficflow_price < $dynamic_price;
		$savings      = array(
			'has_savings'  => false,
			'display_text' => '',
		);
		if ( $candidate && $has_discount ) {
			$discount_type  = $candidate['discount']['discount_type'] ?? '';
			$discount_value = isset( $candidate['discount']['discount_value'] ) ? (float) $candidate['discount']['discount_value'] : 0.0;
			if ( 'percentage' === $discount_type && $discount_value > 0 ) {
				$savings = array(
					'has_savings'  => true,
					'display_text' => sprintf( function_exists( 'pll__' ) ? pll__( 'Save %s%%' ) : 'Save %s%%', number_format( $discount_value, 0 ) ),
				);
			} elseif ( 'fixed' === $discount_type && $discount_value > 0 ) {
				$savings = array(
					'has_savings'  => true,
					'display_text' => sprintf( function_exists( 'pll__' ) ? pll__( 'Save %s' ) : 'Save %s', wc_price( $discount_value ) ),
				);
			} else {
				$monetary = max( 0, $dynamic_price - $trafficflow_price );
				if ( $monetary > 0 ) {
					$savings = array(
						'has_savings'  => true,
						'display_text' => sprintf( function_exists( 'pll__' ) ? pll__( 'Save %s' ) : 'Save %s', wc_price( $monetary ) ),
					);
				}
			}
		}
		$price_html = '';

		if ( $has_discount ) {
			$price_html = sprintf(
				'<span class="trafficflow-price" aria-label="%s">%s</span>
				<span class="trafficflow-original" aria-label="%s">%s</span>',
				esc_attr( function_exists( 'pll__' ) ? pll__( 'Discounted price' ) : 'Discounted price' ),
				wp_kses_post( wc_price( $trafficflow_price ) ),
				esc_attr( function_exists( 'pll__' ) ? pll__( 'Original price' ) : 'Original price' ),
				wp_kses_post( wc_price( $dynamic_price ) )
			);
		} else {
			$price_html = sprintf(
				'<span class="trafficflow-price">%s</span>',
				wp_kses_post( wc_price( $trafficflow_price ) )
			);
		}

		// Always generate savings span for consistent DOM structure (PHP-generated for performance)
		$savings_html = '';
		if ( $has_discount && $savings['has_savings'] && ! empty( $savings['display_text'] ) ) {
			$savings_html = sprintf(
				'<span class="trafficflow-savings" aria-label="%s">%s</span>',
				esc_attr( function_exists( 'pll__' ) ? pll__( 'Savings' ) : 'Savings' ),
				wp_kses_post( $savings['display_text'] )
			);
		} else {
			// Generate empty savings span for JavaScript to update (hidden by default)
			$savings_html = '<span class="trafficflow-savings" style="display: none;"></span>';
		}

		$shipping_cost_note = $this->tfdpa_get_shipping_cost_note();
		if ( ! empty( $shipping_cost_note ) ) {
			$shipping_cost_note = wp_kses_post( $shipping_cost_note );
		}

		printf(
			'<div id="product_total_price" class="trafficflow-pricing">%s%s%s</div>',
			$price_html,
			$savings_html,
			$shipping_cost_note
		);
	}

	/**
	 * Generate the price HTML container for variable products.
	 *
	 * Outputs a container for dynamic pricing, which is updated by JavaScript
	 * when the user selects a variation. Also prepares and localizes pricing
	 * and discount data for frontend scripts.
	 *
	 * @since 1.0.0
	 * @param \WC_Product_Variable $product The variable product object.
	 * @return void
	 */
	private function tfdpa_generate_variable_product_price_html( \WC_Product_Variable $product ): void {
		// Generate a container that JavaScript will populate
		$shipping_cost_note = $this->tfdpa_get_shipping_cost_note();

		// Generate the structure that will be populated by JavaScript
		echo '<div id="product_total_price" class="trafficflow-pricing" data-is-variable="true">
			<span class="trafficflow-price"></span>
			<span class="trafficflow-original" style="display:none;"></span>
			<span class="trafficflow-savings" style="display:none;"></span>
			' . wp_kses_post( $shipping_cost_note ) . '
		</div>';

		// Prepare pricing data for all variations
		$variations_data = array();
		$variations      = $product->get_available_variations();

		foreach ( $variations as $variation_data ) {
			$variation_id = $variation_data['variation_id'];
			$variation    = wc_get_product( $variation_id );

			if ( $variation ) {
				$candidate         = $this->tfdpa_get_best_role_scoped_candidate( $variation, 1 );
				$dynamic_price     = $candidate ? (float) $candidate['base'] : $this->tfdpa_get_dynamic_pricing_product_price( $variation );
				$trafficflow_price = $candidate ? (float) $candidate['final'] : $this->tfdpa_get_role_based_discounted_price( $variation );
				// Build candidate-based savings text
				$savings = array(
					'has_savings'  => false,
					'display_text' => '',
				);
				if ( $candidate && $trafficflow_price < $dynamic_price ) {
					$discount_type  = $candidate['discount']['discount_type'] ?? '';
					$discount_value = isset( $candidate['discount']['discount_value'] ) ? (float) $candidate['discount']['discount_value'] : 0.0;
					if ( 'percentage' === $discount_type && $discount_value > 0 ) {
						$savings = array(
							'has_savings'  => true,
							'display_text' => sprintf( function_exists( 'pll__' ) ? pll__( 'Save %s%%' ) : 'Save %s%%', number_format( $discount_value, 0 ) ),
						);
					} elseif ( 'fixed' === $discount_type && $discount_value > 0 ) {
						$savings = array(
							'has_savings'  => true,
							'display_text' => sprintf( function_exists( 'pll__' ) ? pll__( 'Save %s' ) : 'Save %s', wc_price( $discount_value ) ),
						);
					} else {
						$monetary = max( 0, $dynamic_price - $trafficflow_price );
						if ( $monetary > 0 ) {
							$savings = array(
								'has_savings'  => true,
								'display_text' => sprintf( function_exists( 'pll__' ) ? pll__( 'Save %s' ) : 'Save %s', wc_price( $monetary ) ),
							);
						}
					}
				}

				$variations_data[ $variation_id ] = array(
					'dynamic_price'     => $dynamic_price,
					'trafficflow_price' => $trafficflow_price,
					'has_discount'      => ( $trafficflow_price < $dynamic_price ),
					'savings'           => $savings,
					'price_html'        => wc_price( $trafficflow_price ),
					'original_html'     => wc_price( $dynamic_price ),
				);
			}
		}

		// Add the data to the page for JavaScript to access
		wp_localize_script(
			'tfdpa-frontend-script',
			'tfdpa_variation_prices',
			$variations_data
		);
	}

	/**
	 * Filter the price HTML for shop and taxonomy pages.
	 *
	 * Uses unified role-scoped candidate selection to ensure both original and final prices
	 * come from the same role context, preventing cross-role price mixing.
	 *
	 * @since 1.0.0
	 * @param string      $price_html The original price HTML.
	 * @param \WC_Product $product    The product object.
	 * @return string The filtered price HTML.
	 */
	public function tfdpa_filter_price_html( string $price_html, \WC_Product|\WC_Product_Variable|\WC_Product_Variation $product ): string {
		$is_ajax_or_rest = wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );

		// Return early if not in admin context or AJAX/REST request
		if ( is_admin() && ! $is_ajax_or_rest ) {
			return $price_html;
		}

		// Return early if not in AJAX/REST or shop or product taxonomy context.
		if ( ! $is_ajax_or_rest && ! is_shop() && ! is_product_taxonomy() ) {
			return $price_html;
		}

		// Use unified candidate selection to ensure both prices come from same role
		$candidate = $this->tfdpa_get_best_role_scoped_candidate( $product, 1 );

		if ( $candidate && isset( $candidate['base'] ) && isset( $candidate['final'] ) ) {
			$base_regular = (float) $candidate['base'];
			$final_price  = (float) $candidate['final'];

			// Early return if prices are the same
			if ( $base_regular === $final_price ) {
				return '<span class="final-price">' . wc_price( $final_price ) . '</span>';
			}

			// Compute savings badge and append to price HTML
			$savings = $this->tfdpa_get_savings_display( $product );
			$badge   = ( ! empty( $savings['has_savings'] ) && ! empty( $savings['display_text'] ) )
				? sprintf(
					'<span class="trafficflow-savings-badge onsale" aria-label="%s">%s</span>',
					esc_attr( function_exists( 'pll__' ) ? pll__( 'Savings' ) : 'Savings' ),
					wp_kses_post( $savings['display_text'] )
				)
				: '';

			// Return both prices when there's a discount, both from the same role context
			return '<span class="original-price">' . wc_price( $base_regular ) . '</span><span class="final-price">' . wc_price( $final_price ) . '</span>' . $badge;
		}

		// Fallback to original behavior if no candidate found
		$original_price   = $this->tfdpa_get_dynamic_pricing_product_price( $product );
		$role_based_price = $this->tfdpa_get_role_based_discounted_price( $product );

		// Early return if prices are the same
		if ( $original_price === $role_based_price ) {
			return '<span class="final-price">' . wc_price( $original_price ) . '</span>';
		}

		// Compute savings badge and append to price HTML
		$savings = $this->tfdpa_get_savings_display( $product );
		$badge   = ( ! empty( $savings['has_savings'] ) && ! empty( $savings['display_text'] ) )
			? sprintf(
				'<span class="trafficflow-savings-badge onsale" aria-label="%s">%s</span>',
				esc_attr( function_exists( 'pll__' ) ? pll__( 'Savings' ) : 'Savings' ),
				wp_kses_post( $savings['display_text'] )
			)
			: '';

		// Return both prices when there's a discount
		return '<span class="original-price">' . wc_price( $original_price ) . '</span><span class="final-price">' . wc_price( $role_based_price ) . '</span>' . $badge;
	}

	/**
	 * Get the shipping cost note HTML for product display.
	 *
	 * Generates a localized note about VAT and shipping costs, with an optional
	 * link to the shipping costs page if it exists in the current language.
	 *
	 * @since 1.0.0
	 *
	 * @return string HTML markup for the shipping cost note.
	 */
	private function tfdpa_get_shipping_cost_note(): string {
		$shipping_text = $this->tfdpa_get_localized_shipping_text();
		$shipping_link = $this->tfdpa_get_shipping_page_link();

		// Wrap shipping text in link if page exists
		if ( $shipping_link ) {
			$shipping_text = sprintf(
				/* translators: %1$s: shipping costs text, %2$s: shipping costs link */
				'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
				esc_url( $shipping_link ),
				esc_html( $shipping_text )
			);
		} else {
			// Escape plain text if no link
			$shipping_text = esc_html( $shipping_text );
		}

		$note_text = $this->tfdpa_format_price_note( $shipping_text );

		return sprintf( ' <div class="price-note">%s</div>', $note_text );
	}

	/**
	 * Get localized shipping text.
	 *
	 * @since 1.0.0
	 *
	 * @return string Translated shipping costs text. Note: This string must be escaped (esc_html()) when output.
	 */
	private function tfdpa_get_localized_shipping_text(): string {
		return function_exists( 'pll__' ) ? pll__( 'shipping costs' ) : 'shipping costs';
	}

	/**
	 * Get the permalink for the shipping costs page in the current language.
	 *
	 * @since 1.0.0
	 *
	 * @return string|false The shipping page URL or false if not found.
	 */
	private function tfdpa_get_shipping_page_link() {
		$slug = $this->tfdpa_get_shipping_page_slug();
		$page = get_page_by_path( $slug );

		return $page ? get_permalink( $page ) : false;
	}

	/**
	 * Get the appropriate shipping page slug for the current language.
	 *
	 * Fetches the German page and retrieves its translation for the current language.
	 *
	 * @since 1.0.0
	 *
	 * @return string The page slug for the current language, or empty string if not found.
	 */
	private function tfdpa_get_shipping_page_slug(): string {
		$base_page = get_page_by_path( 'gratis-versand' );
		if ( ! $base_page ) {
			return '';
		}

		// If Polylang is active, get the translated version.
		if ( function_exists( 'pll_get_post' ) ) {
			$current_language = pll_current_language();
			$translated_id    = pll_get_post( $base_page->ID, $current_language );

			if ( $translated_id ) {
				$translated_page = get_post( $translated_id );
				return $translated_page ? $translated_page->post_name : '';
			}
		}

		// Fallback to the base page slug.
		return $base_page->post_name;
	}

	/**
	 * Format the complete price note with VAT and shipping information.
	 *
	 * @since 1.0.0
	 *
	 * @param string $shipping_text The shipping text/link to include in the note (may be HTML or escaped plain text).
	 * @return string The formatted note text. Note: Final output is sanitized with wp_kses_post() in tfdpa_get_shipping_cost_note().
	 */
	private function tfdpa_format_price_note( string $shipping_text ): string {
		$note_template = function_exists( 'pll__' ) ? pll__( '(incl. VAT, plus %s)' ) : '(incl. VAT, plus %s)';

		return sprintf( $note_template, $shipping_text );
	}

	/**
	 * Get the dynamic pricing product price.
	 *
	 * @since 1.0.0
	 * @param \WC_Product $product The product object.
	 * @return float The current product price.
	 */
	private function tfdpa_get_dynamic_pricing_product_price( \WC_Product $product ): float {
		// Remove our own filters
		remove_filter( 'woocommerce_product_get_price', array( $this, 'tfdpa_filter_price' ), 9999 );
		remove_filter( 'woocommerce_product_get_regular_price', array( $this, 'tfdpa_filter_regular_price' ), 9999 );

		$is_variation = $product->is_type( 'variation' );
		if ( $is_variation ) {
			remove_filter( 'woocommerce_product_variation_get_price', array( $this, 'tfdpa_filter_variation_price' ), 9999 );
			remove_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'tfdpa_filter_variation_regular_price' ), 9999 );
		}

		$dynamic = (float) $product->get_price();

		// Re-add filters
		add_filter( 'woocommerce_product_get_price', array( $this, 'tfdpa_filter_price' ), 9999, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'tfdpa_filter_regular_price' ), 9999, 2 );

		if ( $is_variation ) {
			add_filter( 'woocommerce_product_variation_get_price', array( $this, 'tfdpa_filter_variation_price' ), 9999, 2 );
			add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'tfdpa_filter_variation_regular_price' ), 9999, 2 );
		}

		return $dynamic;
	}


	/**
	 * Get the role-based discounted price for a product.
	 *
	 * @since 1.0.0
	 * @param \WC_Product $product The product object.
	 * @return float The discounted price.
	 */
	private function tfdpa_get_role_based_discounted_price( \WC_Product|\WC_Product_Variable|\WC_Product_Variation $product ): float {
		// Unified selection: build best candidate at qty=1 across all role sets; fall back to dynamic base
		$best = $this->tfdpa_get_best_role_scoped_candidate( $product, 1 );
		if ( $best && isset( $best['final'] ) && $best['final'] > 0 ) {
			return max( 0, round( (float) $best['final'], 2 ) );
		}
		return max( 0, round( $this->tfdpa_get_dynamic_pricing_product_price( $product ), 2 ) );
	}

	/**
	 * Build the best role-scoped candidate for a given product and quantity.
	 *
	 * Candidate array structure:
	 * [ 'set_id' => string, 'base' => float, 'final' => float, 'discount' => array ]
	 *
	 * @since 1.0.0
	 * @param \WC_Product $product Product or variation.
	 * @param int         $qty     Quantity context (>=1).
	 * @return array|null Best candidate or null if none qualify.
	 */
	private function tfdpa_get_best_role_scoped_candidate( \WC_Product $product, int $qty ): ?array {
		$user_roles            = $this->tfdpa_get_current_user_roles();
		$trafficflow_discounts = $this->tfdpa_get_trafficflow_discount_data( $product );
		$qty                   = max( 1, (int) $qty );
		$best                  = null;
		// Preload pricing rules to evaluate tier precision and provide tiers in the candidate
		$parent_product = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$pricing_rules  = $this->tfdpa_get_product_pricing_rules( $parent_product );
		if ( empty( $pricing_rules ) ) {
			return null;
		}

		foreach ( $pricing_rules as $set_id => $rule_set ) {
			$discount_data = $trafficflow_discounts[ $set_id ] ?? null;
			if ( ! $this->tfdpa_does_user_qualify_for_discount( $user_roles, (string) $set_id, $product ) ) {
				continue;
			}
			$base = $this->tfdpa_get_base_price_for_set( $product, (string) $set_id, $qty );
			if ( $base <= 0 ) {
				continue;
			}
			// Support hard override price if defined in discount data
			$override_price = null;
			if ( is_array( $discount_data ) && isset( $discount_data['override_price'] ) ) {
				$override_price = (float) $discount_data['override_price'];
			} elseif ( is_array( $discount_data ) && ( $discount_data['discount_type'] ?? '' ) === 'override' && isset( $discount_data['discount_value'] ) ) {
				$override_price = (float) $discount_data['discount_value'];
			}
			if ( null !== $override_price ) {
				$final = (float) $override_price;
			} elseif ( is_array( $discount_data ) ) {
				$final = $this->tfdpa_calculate_discount_price( $base, $discount_data );
			} else {
				$final = (float) $base;
			}
			if ( $final <= 0 ) {
				continue;
			}
			// Determine which role this set targets (first intersection)
			$required_roles = (array) ( is_array( $discount_data ) ? ( $discount_data['required_roles'] ?? array() ) : $this->tfdpa_get_roles_from_pricing_rule( (string) $set_id, $product ) );
			$matching_roles = array_values( array_intersect( $user_roles, $required_roles ) );
			$role_name      = ! empty( $matching_roles ) ? (string) $matching_roles[0] : '';

			// Compute tier precision (smaller width = more precise). Also collect tiers for rendering.
			$tiers       = array();
			$match_width = PHP_INT_MAX;
			$match_from  = 1;
			$match_to    = PHP_INT_MAX;
			if ( isset( $pricing_rules[ $set_id ]['rules'] ) && is_array( $pricing_rules[ $set_id ]['rules'] ) ) {
				$tiers = $pricing_rules[ $set_id ]['rules'];
				foreach ( $tiers as $rule ) {
					$from   = isset( $rule['from'] ) ? (int) $rule['from'] : 1;
					$to_raw = $rule['to'] ?? '';
					$to     = ( '' !== $to_raw && null !== $to_raw ) ? (int) $to_raw : PHP_INT_MAX;
					if ( $qty >= $from && $qty <= $to ) {
						$width       = ( $to === PHP_INT_MAX ) ? PHP_INT_MAX : max( 0, $to - $from );
						$match_from  = $from;
						$match_to    = $to;
						$match_width = $width;
						break;
					}
				}
			}
			if ( null === $best || $final < $best['final'] ) {
				$best = array(
					'set_id'            => (string) $set_id,
					'base'              => (float) $base,
					'final'             => (float) $final,
					'discount'          => is_array( $discount_data ) ? $discount_data : array(),
					'role'              => $role_name,
					'tiers'             => $tiers,
					'has_hard_override' => ( null !== $override_price ),
					'tier_match'        => array(
						'from'  => $match_from,
						'to'    => $match_to,
						'width' => $match_width,
					),
				);
			} elseif ( null !== $best && abs( $final - $best['final'] ) < 0.000001 ) {
				// Tie-break by most precise tier match, then by deterministic role slug
				$best_width = isset( $best['tier_match']['width'] ) ? (int) $best['tier_match']['width'] : PHP_INT_MAX;
				if ( $match_width < $best_width || ( $match_width === $best_width && strcmp( (string) $role_name, (string) $best['role'] ) < 0 ) ) {
					$best = array(
						'set_id'            => (string) $set_id,
						'base'              => (float) $base,
						'final'             => (float) $final,
						'discount'          => is_array( $discount_data ) ? $discount_data : array(),
						'role'              => $role_name,
						'tiers'             => $tiers,
						'has_hard_override' => ( null !== $override_price ),
						'tier_match'        => array(
							'from'  => $match_from,
							'to'    => $match_to,
							'width' => $match_width,
						),
					);
				}
			}
		}

		return $best;
	}

	/**
	 * Get role-scoped base price for a specific pricing set and quantity.
	 *
	 * Selects the matching rule amount from the product's pricing rules for the given set
	 * and quantity. For variations, also ensures the set applies to the variation.
	 * Falls back to the product's regular price when unavailable.
	 *
	 * @since 1.0.0
	 * @param \WC_Product $product The product or variation.
	 * @param string      $set_id  The pricing rule set ID.
	 * @param int         $qty     The quantity context.
	 * @return float The base price for the set.
	 */
	private function tfdpa_get_base_price_for_set( \WC_Product $product, string $set_id, int $qty ): float {
		$qty = max( 1, (int) $qty );

		$parent_product = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$pricing_rules  = $this->tfdpa_get_product_pricing_rules( $parent_product );

		if ( empty( $pricing_rules ) || empty( $pricing_rules[ $set_id ] ) || empty( $pricing_rules[ $set_id ]['rules'] ) ) {
			return (float) $product->get_regular_price();
		}

		// If variation, ensure the set applies to this variation ID
		if ( $product->is_type( 'variation' ) ) {
			$allowed = $pricing_rules[ $set_id ]['variation_rules']['args']['variations'] ?? array();
			if ( is_array( $allowed ) && ! in_array( (string) $product->get_id(), $allowed, true ) ) {
				return (float) $product->get_regular_price();
			}
		}

		$matched_amount = null;
		foreach ( (array) $pricing_rules[ $set_id ]['rules'] as $rule ) {
			$from   = isset( $rule['from'] ) ? (int) $rule['from'] : 1;
			$to_raw = $rule['to'] ?? '';
			$to     = ( '' !== $to_raw && null !== $to_raw ) ? (int) $to_raw : PHP_INT_MAX;
			if ( $qty >= $from && $qty <= $to ) {
				$matched_amount = isset( $rule['amount'] ) ? (float) $rule['amount'] : null;
				break;
			}
		}

		if ( null === $matched_amount ) {
			return (float) $product->get_regular_price();
		}

		return (float) $matched_amount;
	}

	/**
	 * Adjust cart item prices so quantity-aware, role-scoped pricing applies in cart/checkout.
	 *
	 * @since 1.0.0
	 * @param \WC_Cart $cart The cart instance.
	 * @return void
	 */
	public function tfdpa_adjust_cart_item_prices( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( empty( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		// Set cart context flag to prevent price filters from interfering
		$this->is_in_cart_context = true;

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof \WC_Product ) {
				continue;
			}

			$product = $cart_item['data'];
			$qty     = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
			$best    = $this->tfdpa_get_best_role_scoped_candidate( $product, $qty );

			if ( $best && isset( $best['final'] ) && isset( $best['base'] ) ) {
				// Store TrafficFlow pricing data in cart item
				$cart->cart_contents[ $cart_item_key ]['trafficflow_price']          = (float) $best['final'];
				$cart->cart_contents[ $cart_item_key ]['trafficflow_original_price'] = (float) $best['base'];
				$cart->cart_contents[ $cart_item_key ]['trafficflow_discount_data']  = $best['discount'] ?? array();

				// Set the product price to the TrafficFlow price
				$product->set_price( (float) $best['final'] );

			}
		}

		// Reset cart context flag
		$this->is_in_cart_context = false;
	}

	/**
	 * Recalculate cart totals to ensure TrafficFlow pricing is used.
	 *
	 * @since 1.0.0
	 * @param \WC_Cart $cart The cart instance.
	 * @return void
	 */
	public function tfdpa_recalculate_cart_totals( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( empty( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$cart_subtotal           = 0;
		$cart_subtotal_tax       = 0;
		$cart_taxes              = array(); // Track taxes by rate ID
		$has_trafficflow_pricing = false;
		$prices_include_tax      = wc_prices_include_tax();

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['trafficflow_price'] ) ) {
				$trafficflow_price = (float) $cart_item['trafficflow_price'];
				$quantity          = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
				$item_total        = $trafficflow_price * $quantity;

				$item_subtotal = $item_total;
				$item_tax      = 0;
				$item_taxes    = array();

				// Calculate tax based on whether prices include tax
				$product = $cart_item['data'];
				if ( $product->is_taxable() ) {
					$tax_rates = WC_Tax::get_rates( $product->get_tax_class() );

					if ( $prices_include_tax ) {
						// Prices include tax - extract tax from the price
						$taxes         = WC_Tax::calc_tax( $item_total, $tax_rates, true );
						$item_tax      = array_sum( $taxes );
						$item_subtotal = $item_total - $item_tax; // Remove tax to get subtotal
						$item_taxes    = $taxes;
					} else {
						// Prices exclude tax - calculate tax on top
						$taxes      = WC_Tax::calc_tax( $item_total, $tax_rates, false );
						$item_tax   = array_sum( $taxes );
						$item_taxes = $taxes;
					}

					// Aggregate taxes by rate ID
					foreach ( $item_taxes as $rate_id => $tax_amount ) {
						if ( ! isset( $cart_taxes[ $rate_id ] ) ) {
							$cart_taxes[ $rate_id ] = 0;
						}
						$cart_taxes[ $rate_id ] += $tax_amount;
					}
				}

				$cart_subtotal     += $item_subtotal;
				$cart_subtotal_tax += $item_tax;

				// Update the cart item's line totals
				$cart->cart_contents[ $cart_item_key ]['line_subtotal']     = $item_subtotal;
				$cart->cart_contents[ $cart_item_key ]['line_total']        = $item_subtotal;
				$cart->cart_contents[ $cart_item_key ]['line_subtotal_tax'] = $item_tax;
				$cart->cart_contents[ $cart_item_key ]['line_tax']          = $item_tax;

				if ( $product->is_taxable() ) {
					$cart->cart_contents[ $cart_item_key ]['line_tax_data'] = array(
						'total'    => $item_taxes,
						'subtotal' => $item_taxes,
					);
				}

				$has_trafficflow_pricing = true;
			}
		}

		// After the loop, update all cart totals
		if ( $has_trafficflow_pricing ) {
			$cart->set_subtotal( $cart_subtotal );
			$cart->set_subtotal_tax( $cart_subtotal_tax );
			$cart->set_cart_contents_total( $cart_subtotal );
			$cart->set_cart_contents_tax( $cart_subtotal_tax );
			$cart->set_cart_contents_taxes( $cart_taxes );

			// Force recalculate the final total
			$total = $cart_subtotal + $cart_subtotal_tax + $cart->get_shipping_total() + $cart->get_shipping_tax();

			// Apply any discounts
			$total -= ( $cart->get_discount_total() + $cart->get_discount_tax() );

			// Set the total explicitly
			$cart->set_total( $total );
		}
	}

	/**
	 * Get savings display information for a product.
	 *
	 * Returns information about how much the user saves with role-based discounts,
	 * formatted appropriately for percentage or fixed price discounts.
	 *
	 * @since 1.0.0
	 * @param \WC_Product $product The product object.
	 * @return array {
	 *     Savings information array.
	 *
	 *     @type bool   $has_savings    Whether the user has any savings.
	 *     @type string $discount_type  The type of discount ('percentage' or 'fixed').
	 *     @type float  $savings_amount The amount saved (percentage value or monetary amount).
	 *     @type string $display_text   Formatted display text (e.g., 'Save 15%' or 'Save CHF 5.00').
	 * }
	 */
	private function tfdpa_get_savings_display( \WC_Product $product ): array {
		$base_price     = $this->tfdpa_get_dynamic_pricing_product_price( $product );
		$default_result = array(
			'has_savings'    => false,
			'discount_type'  => '',
			'savings_amount' => 0.0,
			'display_text'   => '',
		);

		if ( $base_price <= 0 ) {
			return $default_result;
		}

		$user_roles            = $this->tfdpa_get_current_user_roles();
		$trafficflow_discounts = $this->tfdpa_get_trafficflow_discount_data( $product );

		if ( empty( $trafficflow_discounts ) ) {
			return $default_result;
		}

		$best_price         = $base_price;
		$best_discount_data = null;

		foreach ( $trafficflow_discounts as $set_id => $discount_data ) {
			if ( $this->tfdpa_does_user_qualify_for_discount( $user_roles, $set_id, $product ) ) {
				$discounted_price = $this->tfdpa_calculate_discount_price( $base_price, $discount_data );

				if ( $discounted_price < $best_price ) {
					$best_price         = $discounted_price;
					$best_discount_data = $discount_data;
				}
			}
		}

		if ( ! $best_discount_data || $best_price >= $base_price ) {
			return $default_result;
		}

		$discount_type  = $best_discount_data['discount_type'] ?? '';
		$discount_value = (float) ( $best_discount_data['discount_value'] ?? 0 );

		$result = array(
			'has_savings'   => true,
			'discount_type' => $discount_type,
		);

		switch ( $discount_type ) {
			case 'percentage':
				$result['savings_amount'] = $discount_value;
				$result['display_text']   = sprintf( function_exists( 'pll__' ) ? pll__( 'Save %s%%' ) : 'Save %s%%', number_format( $discount_value, 0 ) );
				break;

			case 'fixed':
				$result['savings_amount'] = $discount_value;
				$result['display_text']   = sprintf( function_exists( 'pll__' ) ? pll__( 'Save %s' ) : 'Save %s', wc_price( $discount_value ) );
				break;

			default:
				$monetary_savings         = $base_price - $best_price;
				$result['savings_amount'] = $monetary_savings;
				$result['display_text']   = sprintf( function_exists( 'pll__' ) ? pll__( 'Save %s' ) : 'Save %s', wc_price( $monetary_savings ) );
				break;
		}

		return $result;
	}

	/**
	 * Get current user roles.
	 *
	 * @since 1.0.0
	 * @return array Array of user roles.
	 */
	private function tfdpa_get_current_user_roles(): array {
		return ( ! is_user_logged_in() ) ? array( 'guest' ) : ( ! empty( wp_get_current_user()->roles ) ? wp_get_current_user()->roles : array( 'customer' ) );
	}

	/**
	 * Get TrafficFlow discount data for the current product.
	 *
	 * @since 1.0.0
	 * @param \WC_Product $product The product object.
	 * @return array Array of discount data indexed by set ID.
	 */
	private function tfdpa_get_trafficflow_discount_data( \WC_Product $product ): array {
		$discount_data = array();

		if ( $product->is_type( 'variation' ) ) {
			// For variations, find all applicable pricing rule sets and get their discount data
			$variation_id    = $product->get_id();
			$parent_id       = $product->get_parent_id();
			$applicable_sets = $this->find_dynamic_pricing_sets_for_variation( $variation_id, $parent_id );

			// Processes discount sets for product variations, logging and storing applicable discount data by user role
			foreach ( $applicable_sets as $set_id ) {
				$trafficflow_discount = $this->get_trafficflow_discount_for_set( $set_id, $variation_id );
				if ( $trafficflow_discount ) {
					$trafficflow_discount['required_roles'] = $this->tfdpa_get_roles_from_pricing_rule( $set_id, $product );
					$discount_data[ $set_id ]               = $trafficflow_discount;
				}
			}
		} else {
			// For simple products, get all pricing rules and their discount data
			$pricing_rules     = $this->tfdpa_get_product_pricing_rules( $product );
			$lookup_product_id = $product->get_id();

			foreach ( $pricing_rules as $set_id => $rule_set ) {
				$meta_key           = "_trafficflow_role_discounts_{$set_id}";
				$rule_discount_data = get_post_meta( $lookup_product_id, $meta_key, true );

				if ( empty( $rule_discount_data ) ) {
					continue;
				}

				// Decode JSON if it's stored as JSON string.
				if ( is_string( $rule_discount_data ) ) {
					$decoded = json_decode( $rule_discount_data, true );
					if ( JSON_ERROR_NONE === json_last_error() ) {
						$rule_discount_data = $decoded;
					}
				}

				// Validate basic structure.
				if ( is_array( $rule_discount_data ) && ! empty( $rule_discount_data['discount_type'] ) && isset( $rule_discount_data['discount_value'] ) ) {
					$rule_discount_data['required_roles'] = $this->tfdpa_get_roles_from_pricing_rule( $set_id, $product );
					$discount_data[ $set_id ]             = $rule_discount_data;
				}
			}
		}

		return $discount_data;
	}

	/**
	 * Get product pricing rules.
	 *
	 * @since 1.0.0
	 * @param \WC_Product $product The product object.
	 * @return array Array of pricing rules.
	 */
	private function tfdpa_get_product_pricing_rules( \WC_Product $product ): array {
		// For variations, get pricing rules from parent product
		$lookup_product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$default_rules     = get_post_meta( $lookup_product_id, '_pricing_rules', true );
		$default_rules     = ( ! empty( $default_rules ) && is_array( $default_rules ) ) ? $default_rules : array();

		$zone_rules = $this->tfdpa_get_zone_pricing_rules( $lookup_product_id );

		if ( empty( $zone_rules ) ) {
			return $default_rules;
		}

		if ( empty( $default_rules ) ) {
			return $zone_rules;
		}

		return $this->combine_pricing_rules_with_zone_prices( $default_rules, $zone_rules );
	}

	/**
	 * Get zone-specific pricing rules from WCPBC or legacy TLD-based meta.
	 */
	private function tfdpa_get_zone_pricing_rules( int $product_id ): array {
		$zone_rules = array();

		// Primary: WCPBC active zone meta.
		if ( function_exists( 'wcpbc_the_zone' ) ) {
			$zone = wcpbc_the_zone();
			if ( $zone ) {
				$zone_rules = $zone->get_postmeta( $product_id, '_pricing_rules' );
			}
		}

		// Fallback: legacy TLD-based meta (_{zone}_pricing_rules).
		if ( empty( $zone_rules ) && function_exists( '_muplugin_get_currency' ) ) {
			$current_zone = strtolower( _muplugin_get_currency( true ) );
			$meta_key     = '_' . $current_zone . '_pricing_rules';
			$legacy_rules = get_post_meta( $product_id, $meta_key, true );
			if ( ! empty( $legacy_rules ) ) {
				$zone_rules = $legacy_rules;
			}
		}

		return ( ! empty( $zone_rules ) && is_array( $zone_rules ) ) ? $zone_rules : array();
	}

	/**
	 * Get roles from a specific pricing rule.
	 *
	 * @since 1.0.0
	 * @param string      $set_id  The pricing rule set ID.
	 * @param \WC_Product $product The product object.
	 * @return array Array of user roles.
	 */
	private function tfdpa_get_roles_from_pricing_rule( string $set_id, \WC_Product $product ): array {
		$role_field_name = "pricing_rule_apply_to_{$set_id}_1_roles";
		if ( isset( $_POST[ $role_field_name ] ) && is_array( $_POST[ $role_field_name ] ) ) {
			return array_map( 'sanitize_text_field', wp_unslash( $_POST[ $role_field_name ] ) );
		}

		// For variations, get pricing rules from parent because rules are stored at parent level
		$parent_product = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$pricing_rules  = $this->tfdpa_get_product_pricing_rules( $parent_product );
		$roles          = array();

		if ( isset( $pricing_rules[ $set_id ]['conditions'] ) ) {
			foreach ( $pricing_rules[ $set_id ]['conditions'] as $condition ) {
				if ( 'apply_to' === ( $condition['type'] ?? '' ) && ! empty( $condition['args']['roles'] ) && is_array( $condition['args']['roles'] ) ) {
					$roles = array_merge( $roles, $condition['args']['roles'] );
				}
			}
		}

		return ! empty( $roles ) ? array_unique( $roles ) : array( 'guest', 'customer' );
	}

	/**
	 * Check if the current user qualifies for a specific discount.
	 *
	 * @since 1.0.0
	 * @param array       $user_roles Array of user roles.
	 * @param string      $set_id     The pricing rule set ID.
	 * @param \WC_Product $product    The product object.
	 * @return bool True if user qualifies, false otherwise.
	 */
	private function tfdpa_does_user_qualify_for_discount( array $user_roles, string $set_id, \WC_Product $product ): bool {
		$required_roles = $this->tfdpa_get_roles_from_pricing_rule( $set_id, $product );
		$matching_roles = array_intersect( $user_roles, $required_roles );

		return ! empty( $matching_roles );
	}

	/**
	 * Apply percentage discount to a base price.
	 *
	 * @since 1.0.0
	 * @param float $base_price The base price.
	 * @param float $percentage The percentage discount to apply.
	 * @return float The discounted price.
	 */
	private function tfdpa_apply_percentage_discount( float $base_price, float $percentage ): float {
		if ( $percentage <= 0 || $percentage > 100 ) {
			return $base_price;
		}

		$discount_amount  = $base_price * ( $percentage / 100 );
		$discounted_price = $base_price - $discount_amount;

		return $discounted_price;
	}

	/**
	 * Apply fixed discount amount.
	 *
	 * @since 1.0.0
	 * @param float $base_price The base price.
	 * @param float $discount_amount The fixed discount amount to subtract from base price.
	 * @return float The discounted price.
	 */
	private function tfdpa_apply_fixed_price( float $base_price, float $discount_amount ): float {
		if ( $discount_amount <= 0 ) {
			return $base_price;
		}

		$discounted_price = $base_price - $discount_amount;

		return $discounted_price;
	}

	/**
	 * Calculate discount price based on discount data.
	 *
	 * @since 1.0.0
	 * @param float $base_price    The base price.
	 * @param array $discount_data The discount data array.
	 * @return float The calculated discount price.
	 */
	private function tfdpa_calculate_discount_price( float $base_price, array $discount_data ): float {
		$discount_type  = $discount_data['discount_type'] ?? '';
		$discount_value = (float) ( $discount_data['discount_value'] ?? 0 );
		switch ( $discount_type ) {
			case 'percentage':
				return $this->tfdpa_apply_percentage_discount( $base_price, $discount_value );
			case 'fixed':
				return $this->tfdpa_apply_fixed_price( $base_price, $discount_value );
			default:
				return $base_price;
		}
	}

	/**
	 * Find all Dynamic Pricing sets for a specific variation.
	 *
	 * @since 1.0.0
	 * @param int $variation_id The variation ID.
	 * @param int $product_id   The parent product ID.
	 * @return array Array of matching set IDs.
	 */
	private function find_dynamic_pricing_sets_for_variation( int $variation_id, int $product_id ): array {
		$pricing_rules = get_post_meta( $product_id, '_pricing_rules', true );
		if ( ! $pricing_rules ) {
			return array();
		}

		$found_sets = array();
		foreach ( $pricing_rules as $set_id => $rule_data ) {
			if (
				isset( $rule_data['variation_rules']['args']['variations'] ) &&
				in_array( (string) $variation_id, $rule_data['variation_rules']['args']['variations'], true )
			) {
				$found_sets[] = $set_id;
			}
		}
		return $found_sets;
	}

	/**
	 * Find Dynamic Pricing set for a specific variation.
	 *
	 * @since 1.0.0
	 * @param int $variation_id The variation ID.
	 * @param int $product_id   The parent product ID.
	 * @return string|null The set ID or null if not found.
	 */
	private function find_dynamic_pricing_set_for_variation( int $variation_id, int $product_id ): ?string {
		$pricing_rules = get_post_meta( $product_id, '_pricing_rules', true );
		if ( ! $pricing_rules ) {
			return null;
		}

		$found_sets = array();
		foreach ( $pricing_rules as $set_id => $rule_data ) {
			if (
				isset( $rule_data['variation_rules']['args']['variations'] ) &&
				in_array( (string) $variation_id, $rule_data['variation_rules']['args']['variations'], true )
			) {
				$found_sets[] = $set_id;
			}
		}
		// Original logic: return first found set
		return ! empty( $found_sets ) ? $found_sets[0] : null;
	}

	/**
	 * Get TrafficFlow discount data for a specific set.
	 *
	 * @since 1.0.0
	 * @param string|null $set_id     The set ID.
	 * @param int         $product_id The product ID.
	 * @return array|null The discount data or null if not found.
	 */
	private function get_trafficflow_discount_for_set( ?string $set_id, int $product_id ): ?array {
		if ( ! $set_id ) {
			return null;
		}

		$meta_key      = '_trafficflow_role_discounts_set_' . $set_id;
		$discount_data = get_post_meta( $product_id, $meta_key, true );

		if ( $discount_data ) {
			$result = is_string( $discount_data ) ? json_decode( $discount_data, true ) : $discount_data;
			if ( is_array( $result ) && ! empty( $result['discount_type'] ) && isset( $result['discount_value'] ) ) {
				return $result;
			}
		}

		$product = wc_get_product( $product_id );
		if ( $product && $product->get_type() === 'variation' ) {
			$parent_id       = $product->get_parent_id();
			$parent_discount = get_post_meta( $parent_id, $meta_key, true );

			if ( $parent_discount ) {
				$result = is_string( $parent_discount ) ? json_decode( $parent_discount, true ) : $parent_discount;
				if ( is_array( $result ) && ! empty( $result['discount_type'] ) && isset( $result['discount_value'] ) ) {
					return $result;
				}
			}
		}

		$alternative_keys = array(
			'trafficflow_role_discounts_set_' . $set_id,  // without leading underscore
			'_trafficflow_discounts_' . $set_id,
			'_role_discounts_set_' . $set_id,
			'_trafficflow_role_discounts_' . $set_id,     // without 'set_' in the middle
		);

		foreach ( $alternative_keys as $alt_key ) {
			$alt_data = get_post_meta( $product_id, $alt_key, true );

			if ( $alt_data ) {
				$result = is_string( $alt_data ) ? json_decode( $alt_data, true ) : $alt_data;
				if ( is_array( $result ) && ! empty( $result['discount_type'] ) && isset( $result['discount_value'] ) ) {
					return $result;
				}
			}

			if ( $product && $product->get_type() === 'variation' ) {
				$parent_alt_data = get_post_meta( $product->get_parent_id(), $alt_key, true );

				if ( $parent_alt_data ) {
					$result = is_string( $parent_alt_data ) ? json_decode( $parent_alt_data, true ) : $parent_alt_data;
					if ( is_array( $result ) && ! empty( $result['discount_type'] ) && isset( $result['discount_value'] ) ) {
						return $result;
					}
				}
			}
		}

		$search_product_id = ( $product && $product->get_type() === 'variation' ) ? $product->get_parent_id() : $product_id;
		$all_meta          = get_post_meta( $search_product_id );

		foreach ( $all_meta as $key => $value ) {
			if ( strpos( $key, 'trafficflow' ) !== false && strpos( $key, $set_id ) !== false ) {
				$result = is_string( $value[0] ) ? json_decode( $value[0], true ) : $value[0];
				if ( is_array( $result ) && ! empty( $result['discount_type'] ) && isset( $result['discount_value'] ) ) {
					return $result;
				}
			}
		}

		return null;
	}

	/**
	 * Get dynamic price for a specific variation.
	 *
	 * @since 1.0.0
	 * @param int $variation_id The variation ID.
	 * @return float The dynamic price.
	 */
	private function get_dynamic_price_for_variation( int $variation_id ): float {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return 0.0;
		}

		remove_filter( 'woocommerce_product_variation_get_price', array( $this, 'tfdpa_filter_variation_price' ), 9999 );
		remove_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'tfdpa_filter_variation_regular_price' ), 9999 );

		$dynamic_price = (float) $variation->get_price();

		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'tfdpa_filter_variation_price' ), 9999, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( $this, 'tfdpa_filter_variation_regular_price' ), 9999, 2 );

		return $dynamic_price;
	}

	/**
	 * Modify pricing table cells to show discounted prices.
	 */
	public function tfdpa_modify_pricing_table_cells( $return, $price ) {
		// Only apply during pricing table generation
		if ( ! isset( $GLOBALS['tfdpa_pricing_context'] ) ) {
			return $return;
		}

		$context       = $GLOBALS['tfdpa_pricing_context'];
		$discount_data = $this->get_trafficflow_discount_for_set( $context['set_id'], $context['product_id'] );

		if ( ! $discount_data || ! $this->tfdpa_does_user_qualify_for_discount( $this->tfdpa_get_current_user_roles(), $context['set_id'], wc_get_product( $context['product_id'] ) ) ) {
			return $return;
		}

		$discounted_price = $this->tfdpa_calculate_discount_price( (float) $price, $discount_data );

		if ( $discounted_price < $price ) {
			return '<div><del>' . $return . '</del></div><div>' . wc_price( $discounted_price ) . '</div>';
		}

		return $return;
	}

	/**
	 * Set pricing table context before wc_price() calls.
	 */
	public function tfdpa_set_pricing_context( $set_id, $product_id ) {
		$GLOBALS['tfdpa_pricing_context'] = array(
			'set_id'     => $set_id,
			'product_id' => $product_id,
		);
	}

	/**
	 * Show pricing rules (replaces the theme function)
	 */
	public function show_pricing_rules(): void {
		global $product;

		// Exit if is_proline_unavailable returns true (you may need to handle this check)
		if ( function_exists( 'is_proline_unavailable' ) && is_proline_unavailable( $product->get_id() ) ) {
			return;
		}

		if ( ! function_exists( '_muplugin_get_currency' ) ) {
			return;
		}

		$nh_user_id        = get_current_user_id();
		$user_capabilities = get_user_meta( $nh_user_id, $GLOBALS['wpdb']->prefix . 'capabilities', true );
		$current_zone      = strtolower( _muplugin_get_currency( true ) ) . '_';

		$prod_id      = $product->get_id();
		$product_type = $product->is_type( 'simple' ) ? 'simple' : 'variable';

		// Fetch default pricing rules at the product level
		$default_pricing = get_post_meta( $prod_id, '_pricing_rules', true );

		if ( $product_type == 'variable' ) {
			$variations = $product->get_available_variations();
			foreach ( $variations as $variation ) {
				$variation_id = $variation['variation_id'];

				// Fetch zone-specific pricing for each variation
				$variation_zone_pricing = get_post_meta( $prod_id, '_' . $current_zone . 'pricing_rules', true );

				if ( ! empty( $variation_zone_pricing ) ) {
					$pricing_groups = $this->combine_pricing_rules_with_zone_prices( $default_pricing, $variation_zone_pricing );
				} else {
					$pricing_groups = $default_pricing;
				}

				$this->build_pricing_table( $user_capabilities, $pricing_groups, $variation_id, $product_type );
			}
		} else {
			// Handle simple products
			$zone_specific_pricing = get_post_meta( $prod_id, '_' . $current_zone . 'pricing_rules', true );
			if ( ! empty( $zone_specific_pricing ) ) {
				$pricing_groups = $this->combine_pricing_rules_with_zone_prices( $default_pricing, $zone_specific_pricing );
			} else {
				$pricing_groups = $default_pricing;
			}

			$this->build_pricing_table( $user_capabilities, $pricing_groups, null, $product_type );
		}
	}

	/**
	 * Combine pricing rules with zone prices
	 */
	private function combine_pricing_rules_with_zone_prices( $default_pricing, $zone_pricing ): array {
		foreach ( $default_pricing as $set_key => &$pricing_set ) {
			if ( isset( $zone_pricing[ $set_key ]['rules'] ) ) {
				foreach ( $pricing_set['rules'] as $rule_key => &$rule ) {
					if ( isset( $zone_pricing[ $set_key ]['rules'][ $rule_key ] ) ) {
						// Update the price, keep the rest of the rule data
						$rule['amount'] = $zone_pricing[ $set_key ]['rules'][ $rule_key ]['amount'];
					}
				}
			}
		}
		return $default_pricing;
	}

	/**
	 * Build pricing table
	 */
	private function build_pricing_table( $user_capabilities, $pricing_groups, $variation_id = null, $product_type = 'variable' ): void {
		$all_pricing_rules          = array();
		$applied_role_specific_rule = false;
		$table_rendered             = false;
		$selected_set_key           = null;
		$selected_final_price       = null;

		// Determine the best role-scoped set using unified candidate selection (qty=1)
		try {
			$current_product      = wc_get_product( get_the_ID() );
			$candidate            = $current_product ? $this->tfdpa_get_best_role_scoped_candidate( $current_product, 1 ) : null;
			$selected_set_key     = $candidate['set_id'] ?? null;
			$selected_final_price = $candidate['final'] ?? null;
		} catch ( \Throwable $e ) {
			// Fail silently; fallback to previous behavior
		}

		foreach ( $pricing_groups as $key => $pricing_group ) {
			// If a selected set was determined, skip other sets to keep table consistent
			if ( $product_type === 'simple' && null !== $selected_set_key && (string) $key !== (string) $selected_set_key ) {
				continue;
			}
			if ( isset( $pricing_group['variation_rules'] ) ) {
				$variation_rules = $pricing_group['variation_rules']['args']['variations'];
				if ( $variation_id !== null ) {
					if ( is_array( $variation_rules ) && ! in_array( $variation_id, $variation_rules ) ) {
						continue;
					}
				} else {
					$variation_id = 'default';
				}
			}

			foreach ( $pricing_group['conditions'] as $condition ) {
				$should_display_table = false;

				if ( $condition['type'] == 'apply_to' ) {
					if ( $condition['args']['applies_to'] == 'roles' ) {
						foreach ( $condition['args']['roles'] as $role ) {
							if ( is_array( $user_capabilities ) && array_key_exists( $role, $user_capabilities ) ) {
								$should_display_table       = true;
								$applied_role_specific_rule = true;
								break;
							}
						}
					} elseif ( $condition['args']['applies_to'] == 'everyone' && $applied_role_specific_rule == false ) {
						$should_display_table = true;
					}
				}

				if ( $should_display_table && ! $table_rendered ) {
					$this->tfdpa_set_pricing_context( $key, get_the_ID() );

					if ( $product_type == 'variable' ) {
						$variation_name = '';
						if ( $variation_id !== null && $variation_id !== 'default' ) {
							$variation      = new WC_Product_Variation( $variation_id );
							$variation_name = wc_get_formatted_variation( $variation, true, false, false );
						}
						$all_pricing_rules[ $variation_name ] = $pricing_group['rules'];
					} else {
						$all_pricing_rules['simple'] = $pricing_group['rules'];
					}

					$variation_data_attr = $product_type == 'variable'
						? 'data-variation-id="' . esc_attr( $variation_id ) . '" data-variation-name="' . esc_attr( $variation_name ) . '"'
						: 'data-product-type="simple"';

					echo '<table ' . $variation_data_attr . ' class="dynamic_pricing_table">';
					echo '<thead><tr><th>' . esc_html( function_exists( 'pll__' ) ? pll__( 'Order Quantity' ) : 'Order Quantity' ) . '</th>';

					$rules = isset( $pricing_group['rules'] ) && is_array( $pricing_group['rules'] ) ? $pricing_group['rules'] : array();
					if ( empty( $rules ) ) {
						echo '<th>1+</th>';
					} else {
						foreach ( $rules as $rule ) {
							$range = ( isset( $rule['to'] ) && $rule['to'] !== '' && $rule['from'] != $rule['to'] )
								? "{$rule['from']}-{$rule['to']}"
								: ( isset( $rule['to'] ) && $rule['from'] == $rule['to'] ? $rule['from'] : "{$rule['from']}+" );
							echo "<th>{$range}</th>";
						}
					}
					echo '</tr></thead>';
					echo '<tbody><tr><td><b>' . esc_html( function_exists( 'pll__' ) ? pll__( 'Unit Price' ) : 'Unit Price' ) . '</b></td>';

					$discount_data  = $this->get_trafficflow_discount_for_set( $key, get_the_ID() );
					$role_qualifies = $this->tfdpa_does_user_qualify_for_discount( $this->tfdpa_get_current_user_roles(), $key, wc_get_product( get_the_ID() ) );

					if ( empty( $rules ) ) {
						// No tiers: render single 1+ cell using selected candidate's final when this is the selected set
						$cell_price = ( (string) $key === (string) $selected_set_key && null !== $selected_final_price ) ? (float) $selected_final_price : 0.0;
						echo '<td>' . wc_price( $cell_price ) . '</td>';
					} else {
						foreach ( $rules as $rule ) {
							$base_amount       = isset( $rule['amount'] ) ? max( 0, (float) $rule['amount'] ) : 0.0;
							$discounted_amount = $base_amount;
							$has_discount      = is_array( $discount_data ) && $role_qualifies;
							if ( $has_discount ) {
								$discounted_amount = $this->tfdpa_calculate_discount_price( $base_amount, $discount_data );
							}
							if ( $has_discount && $discounted_amount < $base_amount ) {
								echo '<td><span class="trafficflow-original"><del>' . wc_price( $base_amount ) . '</del></span><br>' . wc_price( $discounted_amount ) . '</td>';
							} else {
								echo '<td>' . wc_price( $base_amount ) . '</td>';
							}
						}
					}
					echo '</tr></tbody>';
					echo '</table>';

					$table_rendered = true;
					break 2;
				}
			}
		}

		// Output the pricing rules as a JavaScript variable. This is used by the frontend.js file
		// to display the original and discounted prices.
		echo '<script type="text/javascript">';
		if ( $product_type == 'variable' ) {
			echo 'if (typeof pricingRules === "undefined") { var pricingRules = {}; }';
			echo 'Object.assign(pricingRules, ' . json_encode( $all_pricing_rules ) . ');';
		} else {
			echo 'var pricingRules = ' . json_encode( $all_pricing_rules['simple'] ) . ';';
		}
		echo '</script>';
	}
}

// Initialise the main class.
new TrafficFlow_Dynamic_Pricing_Addon_Frontend();
