jQuery( document ).ready( function ( $ ) {
	/**
	 * Normalizes a string by converting to lowercase, handling special
	 * characters, and removing extra whitespace. This helps in matching
	 * variation names which can be inconsistent.
	 */
	function normalizeChars( str ) {
		if ( ! str ) return '';
		return str
			.toLowerCase()
			.replace( /[-.']/g, ' ' )
			.replace( /ü/g, 'ue' )
			.replace( /ö/g, 'oe' )
			.replace( /ä/g, 'ae' )
			.replace( /[éèê]/g, 'e' )
			.replace( /[àâ]/g, 'a' )
			.replace( /[ïî]/g, 'i' )
			.replace( /[ôö]/g, 'o' )
			.replace( /[ùû]/g, 'u' )
			.replace( /ç/g, 'c' )
			.replace( /[^a-z0-9\s]/g, '' )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	/**
	 * Checks if the main product on the page is a variable product with a
	 * selected variation.
	 */
	function isMainProductVariable() {
		// Be more specific - look for the main product container within the main content area
		// This avoids picking up products in search popups or other areas
		const $mainProductForm = $( '#main-container #main article .product form.cart' ).first();
		const $variationSelect = $mainProductForm.find( '.variations select' );
		const selectedVariationName = $variationSelect.first().val();
		const $productElement = $mainProductForm.closest('.product');
		const hasVariableClass = $productElement.hasClass( 'product-type-variable' );

		return (
			hasVariableClass &&
			$variationSelect.length > 0 &&
			selectedVariationName !== undefined &&
			selectedVariationName !== '' &&
			selectedVariationName !== null
		);
	}

	/**
	 * Gets the selected variation name from the main product.
	 */
	function getMainProductVariationName() {
		const $mainProductForm = $( '.product form.cart' ).first();
		const $variationSelect = $mainProductForm.find( '.variations select' );
		return $variationSelect.first().val();
	}

	/**
	 * Retrieves the pricing rules for a specific variation by its name.
	 * It uses the normalizeChars function to ensure a match.
	 * Uses the existing pricingRules object from blocksy-child theme.
	 */
	function getPricingRulesForVariation( variationName ) {
		if ( ! variationName || typeof pricingRules === 'undefined' ) {
			return null;
		}

		const normalizedVariationName = normalizeChars( variationName );
		const matchingKey = Object.keys( pricingRules ).find(
			( key ) => normalizeChars( key ) === normalizedVariationName
		);

		return matchingKey ? pricingRules[ matchingKey ] : null;
	}

	/**
	 * Calculate TrafficFlow discounted price based on discount data.
	 */
	function calculateTrafficFlowPrice( basePrice, variationKey ) {
		if (
			typeof trafficflow_discount_data === 'undefined' ||
			! trafficflow_discount_data.discounts ||
			Object.keys( trafficflow_discount_data.discounts ).length === 0
		) {
			return parseFloat( basePrice.toFixed( 2 ) );
		}

		const userRoles = trafficflow_discount_data.user_roles || [];
		let discounts = trafficflow_discount_data.discounts;
		let bestPrice = basePrice;

		// If variationKey is provided, filter discounts to only those for the selected variation (normalized)
		if ( variationKey && typeof discounts === 'object' ) {
			const normalizedKey = normalizeChars( variationKey );
			const matchingKey = Object.keys( discounts ).find(
				( key ) => normalizeChars( key ) === normalizedKey
			);

			if ( matchingKey ) {
				discounts = { [ matchingKey ]: discounts[ matchingKey ] };
			}
		}

		// Find the best discount for the user
		for ( const [ setId, discountSet ] of Object.entries( discounts ) ) {
			// If discountSet is a single discount object, wrap it in an array for uniformity
			const discountObjects = Array.isArray( discountSet )
				? discountSet
				: typeof discountSet === 'object' &&
				  discountSet !== null &&
				  ! discountSet.discount_type
				? Object.values( discountSet )
				: [ discountSet ];

			discountObjects.forEach( ( discountData, idx ) => {
				// Check if the user qualifies for this discount based on roles
				const requiredRoles = discountData.required_roles || [];
				const userQualifies =
					requiredRoles.length === 0 ||
					userRoles.some( ( role ) =>
						requiredRoles.includes( role )
					);

				if ( ! userQualifies ) {
					return; // Skip this discount if user roles do not match
				}

				if (
					discountData.discount_type &&
					discountData.discount_value
				) {
					// Determine role-scoped base for this set from pricingRules and current qty; fallback to basePrice
					let roleBase = basePrice;
					try {
						const rulesForSet =
							typeof pricingRules !== 'undefined'
								? pricingRules[ setId ]
								: undefined;
						const qtyInput = document.querySelector( 'input.qty' );
						const qty = qtyInput
							? Math.max( 1, parseInt( qtyInput.value, 10 ) || 1 )
							: 1;
						if ( Array.isArray( rulesForSet ) ) {
							for ( const rule of rulesForSet ) {
								const from = parseInt( rule.from, 10 ) || 1;
								const to =
									rule.to === '' ||
									rule.to === null ||
									typeof rule.to === 'undefined'
										? Number.MAX_SAFE_INTEGER
										: parseInt( rule.to, 10 ) ||
										  Number.MAX_SAFE_INTEGER;
								if ( qty >= from && qty <= to ) {
									if (
										typeof rule.amount !== 'undefined' &&
										rule.amount !== null
									) {
										roleBase = parseFloat( rule.amount );
									}
									break;
								}
							}
						}
					} catch ( e ) {
						// silently fallback to basePrice
					}

					let discountedPrice = roleBase;
					if ( discountData.discount_type === 'fixed' ) {
						const discountAmount = parseFloat(
							discountData.discount_value
						);
						discountedPrice = Math.max(
							0,
							roleBase - discountAmount
						);
					} else if ( discountData.discount_type === 'percentage' ) {
						const discount =
							roleBase *
							( parseFloat( discountData.discount_value ) / 100 );
						discountedPrice = roleBase - discount;
					}

					discountedPrice = Math.ceil(discountedPrice * 100) / 100;

					if ( discountedPrice < bestPrice && discountedPrice > 0 ) {
						bestPrice = discountedPrice;
					}
				}
			} );
		}

		return parseFloat( bestPrice.toFixed( 2 ) );
	}

	/**
	 * Calculate savings display text for TrafficFlow discounts
	 * Optimized to use pre-calculated data from PHP for better performance
	 */
	function calculateSavingsDisplay( basePrice, trafficflowPrice ) {
		if ( trafficflowPrice >= basePrice ) {
			return '';
		}

		// Check if TrafficFlow discount data is available
		if (
			typeof trafficflow_discount_data === 'undefined' ||
			! trafficflow_discount_data
		) {
			return '';
		}

		// Use pre-calculated savings display data from PHP if available (preferred method)
		// PHP handles translations, formatting, and currency properly for both percentage and fixed discounts
		if (
			trafficflow_discount_data.savings_display &&
			trafficflow_discount_data.savings_display.has_savings
		) {
			const savingsData = trafficflow_discount_data.savings_display;
			return savingsData.display_text;
		}

		// Fallback: calculate manually using discount rules data
		if (
			! trafficflow_discount_data.discounts ||
			Object.keys( trafficflow_discount_data.discounts ).length === 0
		) {
			return '';
		}

		const userRoles = trafficflow_discount_data.user_roles || [];
		const discounts = trafficflow_discount_data.discounts;
		let bestDiscount = null;
		let bestPrice = basePrice;

		// Find the best discount that would be applied to determine type
		for ( const [ setId, discountData ] of Object.entries( discounts ) ) {
			// Check if the user qualifies for this discount based on roles
			const requiredRoles = discountData.required_roles || [];
			const userQualifies =
				requiredRoles.length === 0 ||
				userRoles.some( ( role ) => requiredRoles.includes( role ) );

			if ( ! userQualifies ) {
				continue; // Skip if user does not qualify
			}
			if ( discountData.discount_type && discountData.discount_value ) {
				let discountedPrice = basePrice;

				if ( discountData.discount_type === 'fixed' ) {
					// Fixed discount is an amount to subtract from base price
					const discountAmount = parseFloat(
						discountData.discount_value
					);
					discountedPrice = Math.max( 0, basePrice - discountAmount );
				} else if ( discountData.discount_type === 'percentage' ) {
					const discount =
						basePrice *
						( parseFloat( discountData.discount_value ) / 100 );
					discountedPrice = basePrice - discount;
				}

				if ( discountedPrice < bestPrice && discountedPrice > 0 ) {
					bestPrice = discountedPrice;
					bestDiscount = discountData;
				}
			}
		}

		if ( ! bestDiscount ) {
			return '';
		}

		// Generate savings text based on discount type (fallback when PHP data unavailable)
		if ( bestDiscount.discount_type === 'percentage' ) {
			return `${ tfdpaL10n.save } ${ Math.round(
				bestDiscount.discount_value
			) }%`;
		} else if ( bestDiscount.discount_type === 'fixed' ) {
			// For fixed discount amounts, the savings is the discount value itself
			const discountAmount = parseFloat( bestDiscount.discount_value );
			const currencySymbol =
				$( '.woocommerce-Price-currencySymbol' ).first().text() ||
				'CHF';
			return `${
				tfdpaL10n.save
			} ${ currencySymbol } ${ discountAmount.toFixed( 2 ) }`;
		}

		return '';
	}

	/**
	 * Updates the price display for a selected variation
	 */
	function updateVariationPriceDisplay( variationId ) {
		if ( typeof tfdpa_variation_prices === 'undefined' ) {
			return;
		}
		if ( ! tfdpa_variation_prices[ variationId ] ) {
			return;
		}

		const variationData = tfdpa_variation_prices[ variationId ];
		const $container = $( '#product_total_price' );
		const $priceSpan = $container.find( '.trafficflow-price' );
		const $originalSpan = $container.find( '.trafficflow-original' );
		const $savingsSpan = $container.find( '.trafficflow-savings' );

		// Update price elements
		$priceSpan.html( variationData.price_html );

		if ( variationData.has_discount ) {
			$originalSpan.html( variationData.original_html ).show();

			if ( variationData.savings && variationData.savings.has_savings ) {
				$savingsSpan.html( variationData.savings.display_text ).show();
			} else {
				$savingsSpan.hide();
			}
		} else {
			$originalSpan.hide();
			$savingsSpan.hide();
		}
	}

	/**
	 * Resets the price display when no variation is selected
	 */
	function resetPriceDisplay() {
		const $container = $( '#product_total_price' );
		$container.find( '.trafficflow-price' ).html( '' );
		$container.find( '.trafficflow-original' ).hide();
		$container.find( '.trafficflow-savings' ).hide();
	}

	/**
	 * The main function that updates the displayed price based on the
	 * selected quantity and variation. This is a direct adaptation of
	 * the logic from the blocksy-child theme.
	 */
	function updatePriceBasedOnQuantity( qty ) {
		// Check if pricingRules is available before proceeding
		if ( typeof pricingRules === 'undefined' ) {
			return; // Exit if pricingRules is not defined
		}

		const isVariable = isMainProductVariable();
		let variationName;
		if ( isVariable ) {
			variationName = getMainProductVariationName();
		}
		const pricingRulesVar = isVariable
			? getPricingRulesForVariation( variationName )
			: pricingRules;
		if ( ! pricingRulesVar ) {
			return; // Exit if no pricing rules found
		}

		const rules = Object.values( pricingRulesVar );
		const lowestPrice = Math.min(
			...rules.map( ( rule ) => parseFloat( rule.amount ) )
		);
		const matchingRule = rules.find( ( rule ) => {
			const from = parseInt( rule.from );
			const to = rule.to ? parseInt( rule.to ) : Infinity;
			const match = qty >= from && qty <= to;
			return match;
		} );
		const dynamicPrice = parseFloat(
			matchingRule ? matchingRule.amount : lowestPrice
		);
		const trafficflowPrice = calculateTrafficFlowPrice(
			dynamicPrice,
			variationName
		);
		const hasDiscount = trafficflowPrice < dynamicPrice;
		const $priceContainer = $( '#product_total_price.trafficflow-pricing' );
		const $priceElement = $priceContainer.find(
			'.trafficflow-price .woocommerce-Price-amount'
		);
		const $originalPriceElement = $priceContainer.find(
			'.trafficflow-original .woocommerce-Price-amount'
		);
		const currencySymbol =
			$priceElement
				.find( '.woocommerce-Price-currencySymbol' )
				.prop( 'outerHTML' ) || '';

		// Update the TrafficFlow discounted price
		if ( $priceElement.length ) {
			$priceElement.html(
				`${ currencySymbol }&nbsp;${ trafficflowPrice.toFixed( 2 ) }`
			);
		}

		// Update the original dynamic pricing price (only if discount exists)
		if ( $originalPriceElement.length && hasDiscount ) {
			$originalPriceElement.html(
				`${ currencySymbol }&nbsp;${ dynamicPrice.toFixed( 2 ) }`
			);
		} else if ( ! hasDiscount ) {
			$originalPriceElement.parent( '.trafficflow-original' ).hide();
		}

		// Update savings display (PHP-generated element)
		const $savingsElement = $priceContainer.find( '.trafficflow-savings' );
		if ( hasDiscount ) {
			const savingsText = calculateSavingsDisplay(
				dynamicPrice,
				trafficflowPrice
			);
			if ( savingsText && $savingsElement.length ) {
				$savingsElement.html( savingsText );
				$savingsElement.show();
			} else if ( savingsText && ! $savingsElement.length ) {
				const $originalPriceSpan = $priceContainer.find(
					'.trafficflow-original'
				);
				const $priceNoteDiv = $priceContainer.find( '.price-note' );

				if ( $originalPriceSpan.length && $priceNoteDiv.length ) {
					$priceNoteDiv.before(
						`<span class="trafficflow-savings">${ savingsText }</span>`
					);
				} else if ( $originalPriceSpan.length ) {
					$originalPriceSpan.after(
						`<span class="trafficflow-savings">${ savingsText }</span>`
					);
				} else {
					$priceContainer.append(
						`<span class="trafficflow-savings">${ savingsText }</span>`
					);
				}
			}
		} else {
			// Hide savings display if no discount (don't remove, just hide)
			if ( $savingsElement.length ) {
				$savingsElement.hide();
			}
		}
	}

	/**
	 * Function to handle quantity changes from any source
	 */
	function onQuantityChange() {
		const $mainProductForm = $( '#main-container #main article .product form.cart' ).first();
		const $quantityInput = $mainProductForm.find(
			'input[name="quantity"]'
		);
		const qty = parseInt( $quantityInput.val(), 10 ) || 1;

		updatePriceBasedOnQuantity( qty );
	}

	/**
	 * Handle TrafficFlow pricing for variable products
	 * Updates price display when variations change
	 */
	function initVariableProductPricing() {
		const $pricingContainer = $( '#product_total_price' );
		if (
			! $pricingContainer.length ||
			$pricingContainer.data( 'is-variable' ) !== true
		) {
			return;
		}

		// Listen for variation changes
		$( document.body ).on(
			'found_variation',
			'form.cart',
			function ( event, variation ) {
				updateVariationPriceDisplay( variation.variation_id );
				togglePricingTable();
				onQuantityChange();
			}
		);

		// Clear prices when no variation is selected
		$( document.body ).on( 'reset_data', 'form.cart', function () {
			resetPriceDisplay();
			togglePricingTable();
		} );
	}

	/**
	 * Function to toggle the visibility of the pricing table
	 */
	function togglePricingTable() {
		const isVariableProduct = isMainProductVariable();
		const selectedVariationName = getMainProductVariationName();

		if ( isVariableProduct ) {
			$( '.dynamic_pricing_table' ).hide();

			var $table = $(
				'.dynamic_pricing_table[data-variation-name="' +
					selectedVariationName +
					'"]'
			);
			if ( $table.length > 0 ) {
				$table.show();
			} else {
				let foundCaseInsensitive = false;
				let matchedTable = null;
				$( '.dynamic_pricing_table[data-variation-name]' ).each(
					function () {
						var tableName = $( this ).attr( 'data-variation-name' );
						var normalizedTableName = normalizeChars( tableName );
						var normalizedSelectedName = normalizeChars(
							selectedVariationName
						);

						if ( normalizedTableName === normalizedSelectedName ) {
							foundCaseInsensitive = true;
							matchedTable = $( this );
							return false; // break the loop
						}
					}
				);

				if ( foundCaseInsensitive && matchedTable ) {
					matchedTable.show();
				}
			}
		} else {
			// For simple products, show the default pricing table (if it exists)
			$( '.dynamic_pricing_table' ).hide();
			var $defaultTable = $(
				'.dynamic_pricing_table:not([data-variation-name])'
			);
			if ( $defaultTable.length > 0 ) {
				$defaultTable.show();
			}
		}
	}

	// Listen for clicks on plus/minus buttons.
	$( document ).on( 'click', '.plus, .minus', function () {
		setTimeout( () => {
			onQuantityChange();
		}, 100 );
	} );

	// Handle direct input changes to quantity field
	$( document ).on( 'change input', '.input-text.qty', function () {
		onQuantityChange();
	} );

	togglePricingTable();
	initVariableProductPricing();
} );
