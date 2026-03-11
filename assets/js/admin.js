const wrapper = document.querySelectorAll( '.woocommerce_pricing_ruleset' );

wrapper.forEach( ( wrapper ) => {
	const uid = wrapper.id.match( /set_(\w+)/ )[ 1 ];
	const rulesTable = wrapper.querySelector(
		'#woocommerce-pricing-rules-table-set_' + uid
	);
	const dateFields = wrapper.querySelector(
		'.section.pricing-rule-date-fields'
	);
	const newContainer = document.createElement( 'div' );
	newContainer.classList.add( 'role-based-discounts' );
	newContainer.innerHTML = `
		<div class="discount-type-controls section">
			<label for="discount-type-set_${ uid }">${ tfdpaL10n.discountType }</label>
			<select class="discount-type" id="discount-type-set_${ uid }" name="trafficflow_discount_type[set_${ uid }]">
				<option value="none">${ tfdpaL10n.none }</option>
				<option value="fixed">${ tfdpaL10n.fixed }</option>
				<option value="percentage">${ tfdpaL10n.percentage }</option>
			</select>
		</div>
		<div class="discount-value-controls section">
			<label for="discount-value-set_${ uid }">${ tfdpaL10n.discountValue }</label>
			<input type="text" id="discount-value-set_${ uid }" class="discount-value" name="trafficflow_discount_value[set_${ uid }]">
		</div>
	`;

	dateFields.after( newContainer );

	const discountValueControls = newContainer.querySelector(
		'.discount-value-controls'
	);
	const discountType = newContainer.querySelector( '.discount-type' );
	const discountValue = newContainer.querySelector( '.discount-value' );

	// Helpers to work with WooCommerce localized numbers.
	const parseLocalizedNumber = ( raw ) => {
		if ( typeof raw !== 'string' ) {
			return null;
		}

		const trimmed = raw.trim();

		if ( trimmed === '' ) {
			return null;
		}

		// WooCommerce may expose separators globally or via wcSettings.
		const decimalSep =
			window.wc_decimal_separator ||
			( window.wcSettings &&
				window.wcSettings.currency &&
				window.wcSettings.currency.decimalSeparator ) ||
			'.';
		const thousandSep =
			window.wc_thousand_separator ||
			( window.wcSettings &&
				window.wcSettings.currency &&
				window.wcSettings.currency.thousandSeparator ) ||
			',';

		let normalized = trimmed;

		// Remove thousand separators.
		if ( thousandSep ) {
			const escThousand = thousandSep.replace(
				/[.*+?^${}()|[\]\\]/g,
				'\\$&'
			);
			normalized = normalized.replace(
				new RegExp( escThousand, 'g' ),
				''
			);
		}

		// Replace decimal separator with dot.
		if ( decimalSep && decimalSep !== '.' ) {
			const escDecimal = decimalSep.replace(
				/[.*+?^${}()|[\]\\]/g,
				'\\$&'
			);
			normalized = normalized.replace(
				new RegExp( escDecimal, 'g' ),
				'.'
			);
		}

		const parsed = parseFloat( normalized );

		return Number.isNaN( parsed ) ? null : parsed;
	};

	const formatLocalizedNumber = ( value, decimals = 2 ) => {
		if ( typeof value !== 'number' || Number.isNaN( value ) ) {
			return '';
		}

		const decimalSep =
			window.wc_decimal_separator ||
			( window.wcSettings &&
				window.wcSettings.currency &&
				window.wcSettings.currency.decimalSeparator ) ||
			'.';
		const thousandSep =
			window.wc_thousand_separator ||
			( window.wcSettings &&
				window.wcSettings.currency &&
				window.wcSettings.currency.thousandSeparator ) ||
			',';

		if ( typeof window.accounting !== 'undefined' ) {
			return window.accounting.formatNumber(
				value,
				decimals,
				thousandSep,
				decimalSep
			);
		}

		let result = value.toFixed( decimals );

		if ( decimalSep !== '.' ) {
			result = result.replace( '.', decimalSep );
		}

		return result;
	};

	const getLowestRuleAmount = () => {
		if ( ! rulesTable ) {
			return null;
		}

		const amountInputs = rulesTable.querySelectorAll( '.float_rule_number' );
		let lowest = null;

		amountInputs.forEach( ( input ) => {
			const parsed = parseLocalizedNumber( input.value );

			if ( parsed === null ) {
				return;
			}

			if ( lowest === null || parsed < lowest ) {
				lowest = parsed;
			}
		} );

		return lowest;
	};

	discountType.addEventListener( 'change', function () {
		const selectedValue = this.value;
		if ( selectedValue === 'fixed' || selectedValue === 'percentage' ) {
			discountValueControls.style.display = 'block';
			discountValue.required = true;

			if ( selectedValue === 'fixed' ) {
				discountValue.placeholder = tfdpaL10n.enterFixedAmount;
				discountValue.min = '0';
				const lowestAmount = getLowestRuleAmount();
				discountValue.max = lowestAmount !== null ? String( lowestAmount ) : '';
				discountValue.step = '0.01';
			} else {
				discountValue.placeholder = tfdpaL10n.enterPercentage;
				discountValue.min = '0';
				discountValue.max = '100';
				discountValue.step = '0.01';
			}
		} else {
			discountValueControls.style.display = 'none';
			discountValue.required = false;
		}
	} );

	// Validate and clamp when the value is "final" using WooCommerce-style formatting.
	discountValue.addEventListener( 'change', function () {
		const parsed = parseLocalizedNumber( this.value );

		if ( parsed === null ) {
			return;
		}

		let value = parsed;

		const min = this.min !== '' && ! Number.isNaN( parseFloat( this.min ) ) ? parseFloat( this.min ) : null;
		const max = this.max !== '' && ! Number.isNaN( parseFloat( this.max ) )  ? parseFloat( this.max ) : null;

		if ( max !== null && value > max ) {
			value = max;
		}

		if ( min !== null && value < min ) {
			value = min;
		}

		this.value = formatLocalizedNumber( value );
	} );

	if ( rulesTable ) {
		rulesTable.addEventListener( 'input', ( event ) => {
			const target = event.target;

			if (
				! target.classList.contains( 'float_rule_number' ) ||
				discountType.value !== 'fixed'
			) {
				return;
			}

			const lowestAmount = getLowestRuleAmount();
			discountValue.max = lowestAmount !== null ? String( lowestAmount ) : '';

			discountValue.dispatchEvent( new Event( 'change' ) );
		} );
	}

	if (
		typeof window.tfdpaExistingDiscounts !== 'undefined' &&
		window.tfdpaExistingDiscounts[ 'set_' + uid ]
	) {
		const existingData = window.tfdpaExistingDiscounts[ 'set_' + uid ];
		discountType.value = existingData.discount_type;

		if ( existingData.discount_value ) {
			discountValue.value = existingData.discount_value;
		}

		discountType.dispatchEvent( new Event( 'change' ) );
	}
} );
