<?php
/**
 * Products class.
 *
 * Manages Products and their integration as Line Items.
 *
 * @package WPCV_Woo_Civi
 * @since 3.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Products class.
 *
 * @since 3.0
 */
class WPCV_Woo_Civi_Products {

	/**
	 * WooCommerce Product meta key holding the CiviCRM Financial Type ID.
	 *
	 * @since 3.0
	 * @access public
	 * @var str $meta_key The WooCommerce Order meta key.
	 */
	public $meta_key = '_woocommerce_civicrm_financial_type_id';

	/**
	 * Class constructor.
	 *
	 * @since 3.0
	 */
	public function __construct() {

		// Init when this plugin is fully loaded.
		add_action( 'wpcv_woo_civi/loaded', [ $this, 'initialise' ] );

	}

	/**
	 * Initialise this object.
	 *
	 * @since 3.0
	 */
	public function initialise() {
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @since 3.0
	 */
	public function register_hooks() {

		// Add Line Items to Order.
		add_filter( 'wpcv_woo_civi/contribution/create_from_order/params', [ $this, 'items_get_for_order' ], 30, 2 );
		add_filter( 'wpcv_woo_civi/contribution/create_from_order/params', [ $this, 'shipping_get_for_order' ], 40, 2 );

		// Get Line Items for Payment.
		//add_filter( 'wpcv_woo_civi/contribution/payment_create/params', [ $this, 'items_get_for_payment' ], 10, 3 );

	}

	/**
	 * Filter the Payment params before calling the CiviCRM API.
	 *
	 * @since 3.0
	 *
	 * @param array $params The params to be passed to the CiviCRM API.
	 * @param object $order The WooCommerce Order object.
	 * @param array $contribution The CiviCRM Contribution data.
	 */
	public function items_get_for_payment( $params, $order, $contribution ) {

		// Try and get the Line Items.
		$items = $this->items_get_by_contribution_id( $contribution['id'] );
		if ( empty( $items ) ) {
			return $params;
		}

		/*
		 * Build Line Item data. For example:
		 *
		 * 'line_item' => [
		 *   '0' => [
		 *     '1' => 10,
		 *   ],
		 *   '1' => [
		 *     '2' => 40,
		 *   ],
		 * ],
		 */
		$items_data = [];
		$count = 0;
		foreach ( $items as $item ) {
			$line_item = [ (string) $count ];
			$count++;
			$line_item[ (string) $count ] = (float) $item['line_total'] + (float) $item['tax_amount'];
			$items_data[] = [ $line_item ];
		}

		$params['line_item'] = $items_data;

		return $params;

	}

	/**
	 * Filter the Payment params before calling the CiviCRM API.
	 *
	 * @since 3.0
	 *
	 * @param int $contribution_id The numeric ID of the CiviCRM Contribution.
	 * @return array $result The CiviCRM Line Item data, or empty on failure.
	 */
	public function items_get_by_contribution_id( $contribution_id ) {

		// Bail if we have no Contribution ID.
		if ( empty( $contribution_id ) ) {
			return [];
		}

		// Bail if we can't initialise CiviCRM.
		if ( ! WPCV_WCI()->boot_civi() ) {
			return [];
		}

		// Construct API query.
		$params = [
			'version' => 3,
			'contribution_id' => $contribution_id,
		];

		// Get Line Item details via API.
		$result = civicrm_api( 'LineItem', 'get', $params );

		// Bail if there's an error.
		if ( ! empty( $result['is_error'] ) AND $result['is_error'] == 1 ) {

			// Write to CiviCRM log.
			CRM_Core_Error::debug_log_message( __( 'Error trying to find Line Items by Contribution ID', 'wpcv-woo-civi-integration' ) );

			// Write details to PHP log.
			$e = new \Exception();
			$trace = $e->getTraceAsString();
			error_log( print_r( [
				'method' => __METHOD__,
				'params' => $params,
				'backtrace' => $trace,
			], true ) );

			return [];

		}

 		// The result set is what we want.
		$line_items = [];
		if ( ! empty( $result['values'] ) ) {
			$line_items = $result['values'];
		}

		return $line_items;

	}

	/**
	 * Gets the Financial Type ID from WooCommerce Product meta.
	 *
	 * @since 3.0
	 *
	 * @param int $product_id The Product ID.
	 * @return int|bool $financial_type_id The Financial Type ID, false otherwise.
	 */
	public function get_product_meta( $product_id ) {
		$financial_type_id = get_post_meta( $product_id, $this->meta_key, true );
		return (int) $financial_type_id;
	}

	/**
	 * Sets the CiviCRM Financial Type ID as meta data on a WooCommerce Product.
	 *
	 * @since 3.0
	 *
	 * @param int $product_id The Product ID.
	 * @param int $financial_type_id The numeric ID of the Financial Type.
	 */
	public function set_product_meta( $product_id, $financial_type_id ) {
		update_post_meta( $product_id, $this->meta_key, (int) $financial_type_id );
	}

	/**
	 * Gets the Line Items for an Order.
	 *
	 * @since 3.0
	 *
	 * @param array $params The existing array of params for the CiviCRM API.
	 * @param object $order The Order object.
	 * @return array $params The modified array of params for the CiviCRM API.
	 */
	public function items_get_for_order( $params, $order ) {

		$items = $order->get_items();

		// Add note.
		$params['note'] = $this->note_generate( $items );

		// Init Line Items.
		$params['line_items'] = [];

		// Filter the params and add Line Items.
		$params = $this->items_build_for_order( $params, $order, $items );

		return $params;

	}

	/**
	 * Gets the Line Items for an Order.
	 *
	 * @see https://docs.civicrm.org/dev/en/latest/financial/orderAPI/#step-1
	 *
	 * @since 2.2 Line Items added to CiviCRM Contribution.
	 * @since 3.0 Logic moved to this method
	 *
	 * @param array $params The existing array of params for the CiviCRM API.
	 * @param object $order The Order object.
	 * @param array $items The array of Items in the Order.
	 * @return array $params The modified array of params for the CiviCRM API.
	 */
	public function items_build_for_order( $params, $order, $items ) {

		// Bail if no Items.
		if ( empty( $items ) ) {
			return $params;
		}

		$default_price_set_data = $this->get_default_price_set_data();
		if ( empty( $default_price_set_data ) ) {
			return $params;
		}

		// Track unique Financial Types in the Line Items.
		$financial_types = [];

		foreach ( $items as $item_id => $item ) {

			$product = $item->get_product();

			// Does this Product have a Financial Type?
			$product_financial_type_id = $product->get_meta( $this->meta_key );

			// Skip if this Product should not be synced to CiviCRM.
			if ( 'exclude' === $product_financial_type_id ) {
				continue;
			}

			$line_item_data = [
				'price_field_id' => $default_price_set_data['price_field']['id'],
				'unit_price' => WPCV_WCI()->helper->get_civicrm_float( $product->get_price() ),
				'qty' => $item->get_quantity(),
				// The "line_total" must equal the unit_price × qty.
				'line_total' => WPCV_WCI()->helper->get_civicrm_float( $item->get_total() ),
				'tax_amount' => WPCV_WCI()->helper->get_civicrm_float( $item->get_total_tax() ),
				'label' => $product->get_name(),
				'entity_table' => 'civicrm_contribution',
			];

			/*
			 * From the docs:
			 *
			 * The Line Item will inherit the "financial_type_id" from the contribution
			 * if it is not set.
			 *
			 * @see https://docs.civicrm.org/dev/en/latest/financial/orderAPI/
			 *
			 * This means that we only need to set this here if it's different to
			 * the one set on the Contribution itself.
			 *
			 * In practice, all Products should have an appropriate Financial Type
			 * set, otherwise bad things will happen.
			 */
			if ( ! empty( $product_financial_type_id ) ) {
				$line_item_data['financial_type_id'] = $product_financial_type_id;
			}

			// Construct Line Item.
			$line_item = [
				'params' => [],
				'line_item' => [ $line_item_data ],
			];

			/**
			 * Filter the Line Item.
			 *
			 * Used internally by:
			 *
			 * * WPCV_Woo_Civi_Tax::line_item_filter() (Priority: 10)
			 * * WPCV_Woo_Civi_Membership::line_item_filter() (Priority: 20)
			 *
			 * @since 3.0
			 *
			 * @param array $line_item The array of Line Item data.
			 * @param object $item The WooCommerce Item object.
			 * @param object $product The WooCommerce Product object.
			 * @param object $order The WooCommerce Order object.
			 * @param array $params The params as presently constructed.
			 */
			$line_item = apply_filters( 'wpcv_woo_civi/products/line_item', $line_item, $item, $product, $order, $params );

			$params['line_items'][ $item_id ] = $line_item;

			// Store (or overwrite) entry in Financial Types array.
			$financial_types[ $product_financial_type_id ] = $product_financial_type_id;

		}

		/*
		 * When there is only one Financial Type for the Line Items - or there is
		 * only one Line Item:
		 *
		 * Override the Contribution's Financial Type because the item(s) may have
		 * been modified to become Membership(s) or Event Participant(s) and the
		 * "parent" Contribution Financial Type should reflect this.
		 *
		 * When there are multiple Line Items with different Financial Types, this
		 * from Rich Lott @artfulrobot:
		 *
		 * "Regarding the Contribution's Financial Type ID, you should omit the
		 * top level one. There was a bug about that (there may still be a bug around
		 * that) but if you have it in ALL your line items, that should do."
		 *
		 * If only that worked. I thought it sounded too good to be true. Doing that
		 * results in: "Mandatory key(s) missing from params array: financial_type_id"
		 * so let's try the default and see what happens.
		 */
		if ( 1 === count( $financial_types ) ) {
			$params['financial_type_id'] = array_pop( $financial_types );
		} else {
			$params['financial_type_id'] = get_option( 'woocommerce_civicrm_financial_type_id' );
		}

		return $params;

	}

	/**
	 * Gets the Shipping Line Item for an Order.
	 *
	 * @since 3.0
	 *
	 * @param array $params The existing array of params for the CiviCRM API.
	 * @param object $order The Order object.
	 * @return array $params The modified array of params for the CiviCRM API.
	 */
	public function shipping_get_for_order( $params, $order ) {

		$items = $order->get_items();

		// Bail if no Items.
		if ( empty( $items ) ) {
			return $params;
		}

		$default_price_set_data = $this->get_default_price_set_data();
		if ( empty( $default_price_set_data ) ) {
			return $params;
		}

		// Grab Shipping cost and sanity check.
		$shipping_cost = $order->get_total_shipping();
		if ( empty( $shipping_cost ) ) {
			$shipping_cost = 0;
		}

		// Ensure number format is CiviCRM-compliant.
		$shipping_cost = WPCV_WCI()->helper->get_civicrm_float( $shipping_cost );
		if ( ! ( floatval( $shipping_cost ) > 0 ) ) {
			return $params;
		}

		// Get the default Financial Type Shipping ID.
		$default_financial_type_shipping_id = get_option( 'woocommerce_civicrm_financial_type_shipping_id' );

		// Needs to be defined in Settings.
		if ( empty( $default_financial_type_shipping_id ) ) {

			// Write message to CiviCRM log.
			$message = __( 'There must be a default Shipping Financial Type set.', 'wpcv-woo-civi-integration' );
			CRM_Core_Error::debug_log_message( $message );

			// Write details to PHP log.
			$e = new \Exception();
			$trace = $e->getTraceAsString();
			error_log( print_r( [
				'method' => __METHOD__,
				'message' =>  $message,
				'params' => $params,
				'backtrace' => $trace,
			], true ) );

			return $params;

		}

		/*
		 * Line item for shipping.
		 *
		 * Shouldn't it be added to it's corresponding Product/Line Item?
		 * i.e. an Order can have both shippable and downloadable Products?
		 */
		$params['line_items'][0] = [
			'line_item' => [
				[
					'price_field_id' => $default_price_set_data['price_field']['id'],
					'qty' => 1,
					'line_total' => $shipping_cost,
					'unit_price' => $shipping_cost,
					'label' => 'Shipping',
					'financial_type_id' => $default_financial_type_shipping_id,
				]
			],
		];

		return $params;

	}

	/**
	 * Get the Contribution amount data from default Price Set.
	 *
	 * Values retrieved are: price set, price_field, and price field value.
	 *
	 * @since 2.4
	 *
	 * @return array $default_price_set_data The default Price Set data.
	 */
	public function get_default_price_set_data() {

		static $default_price_set_data;
		if ( isset( $default_price_set_data ) ) {
			return $default_price_set_data;
		}

		// Bail if we can't initialise CiviCRM.
		if ( ! WPCV_WCI()->boot_civi() ) {
			return [];
		}

		$params = [
			'name' => 'default_contribution_amount',
			'is_reserved' => true,
			'api.PriceField.getsingle' => [
				'price_set_id' => "\$value.id",
				'options' => [
					'limit' => 1,
					'sort' => 'id ASC',
				],
			],
		];

		try {

			$price_set = civicrm_api3( 'PriceSet', 'getsingle', $params );

		} catch ( CiviCRM_API3_Exception $e ) {

			// Write to CiviCRM log.
			CRM_Core_Error::debug_log_message( __( 'Unable to retrieve default Price Set', 'wpcv-woo-civi-integration' ) );
			CRM_Core_Error::debug_log_message( $e->getMessage() );

			// Write details to PHP log.
			$e = new \Exception();
			$trace = $e->getTraceAsString();
			error_log( print_r( [
				'method' => __METHOD__,
				'params' => $params,
				'backtrace' => $trace,
			], true ) );

			return [];

		}

		$price_field = $price_set['api.PriceField.getsingle'];
		unset( $price_set['api.PriceField.getsingle'] );

		$default_price_set_data = [
			'price_set' => $price_set,
			'price_field' => $price_field,
		];

		return $default_price_set_data;

	}

	/**
	 * Create string to insert for Purchase Activity Details.
	 *
	 * @since 2.0
	 *
	 * @param object $items The Order object.
	 * @return string $str The Purchase Activity Details.
	 */
	public function note_generate( $items ) {

		$str = '';
		$n = 1;
		foreach ( $items as $item ) {
			if ( $n > 1 ) {
				$str .= ', ';
			}
			$str .= $item['name'] . ' x ' . $item['quantity'];
			$n++;
		}

		return $str;

	}

}
