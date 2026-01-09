<?php
/**
 * Plugin Name: Envios da Olist
 * Plugin URI: ttps://envios.olist.com/integracoes/woocommerce
 * Description: Envios da Olist para WooCommerce
 * Version: 1.0.0
 * Author: Olist
 * Author URI: https://olist.com/envios
 * Text Domain: olist-envios
 */

function olist_envios_init() {
	if ( class_exists( 'woocommerce' ) && class_exists( 'WC_Shipping_Method' ) && function_exists( 'WC' ) ) {
		// We add an action to init our shipping method class, and a filter to add our shipping method to the method list.
		add_action( 'woocommerce_shipping_init', 'olist_envios_shipping_method_init' );
		add_filter( 'woocommerce_shipping_methods', 'olist_envios_shipping_method_add' );
		// Filter to customize shipping method label HTML to show delivery time
		add_filter( 'woocommerce_cart_shipping_method_full_label', 'olist_envios_display_delivery_time', 10, 2 );
		// Hook to add delivery time after shipping rate (alternative method)
		add_action( 'woocommerce_after_shipping_rate', 'olist_envios_display_delivery_time_after_rate', 10, 2 );
		// Hook to set delivery_time on rate after it's created
		add_filter( 'woocommerce_shipping_method_add_rate', 'olist_envios_set_delivery_time_on_rate', 10, 3 );
		// Filter to format delivery_time with "dias úteis"
		add_filter( 'woocommerce_shipping_rate_delivery_time', 'olist_envios_format_delivery_time', 10, 2 );
	}
}
add_action( 'plugins_loaded', 'olist_envios_init' );

function olist_envios_shipping_method_add( $methods ) {
	$methods['olist_envios'] = 'WC_Olist_Envios_Shipping_Method';
	return $methods;
}

function olist_envios_shipping_method_init() {
	if ( ! class_exists( 'WC_Olist_Envios_Shipping_Method' ) ) {
		class WC_Olist_Envios_Shipping_Method extends WC_Shipping_Method {
			/**
			 * Constructor for your shipping class.
			 *
			 * @param  int  $instance_id Shipping method instance ID. A new instance ID is assigned per instance created in a shipping zone.
			 * @return void
			 */
			public function __construct( $instance_id = 0 ) {
				$this->id                 = 'olist_envios'; // ID for your shipping method. Should be unique.
				$this->instance_id        = absint( $instance_id );
				$this->method_title       = __( 'Envios da Olist', 'olist-envios' );  // Title shown in admin.
				$this->method_description = __( 'Integração oficial de fretes Envios da Olist', 'olist-envios' ); // Description shown in admin.
				$this->supports           = array(
					'settings',                // Provides a stand alone settings tab for your shipping method under WooCommerce > Settings > Shipping.
					'shipping-zones',          // Allows merchants to add your shipping method to shipping zones.
					'instance-settings',       // Allows for a page where merchants can edit the instance of your method included in a shipping zone.
					'instance-settings-modal', // Allows for a modal where merchants can edit the instance of your method included in a shipping zone.
				);

				$this->init();
			}

			/**
			 * Additional initialization of options for the shipping method not necessary in the constructor.
			 *
			 * @return void
			 */
			public function init() {
				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
				$this->init_form_fields();
				$this->init_instance_form_fields();
				$this->title = $this->get_option( 'title' );
			}

			/**
			 * Calculate the shipping costs.
			 *
			 * @param array $package Package of items from cart.
			 */
			public function calculate_shipping( $package = array() ) {
				$this->add_rates_from_json( $this->get_shipping_quote($package) );

			/**
			 * Developers can add additional flat rates based on this one via this action since @version 2.4.
			 *
			 * Previously there were (overly complex) options to add additional rates however this was not user.
			 * friendly and goes against what Flat Rate Shipping was originally intended for.
			 */
			do_action( 'woocommerce_' . $this->id . '_shipping_add_rate', $this );
			}

            private function get_shipping_quote($package) {
				$url = 'https://envios-api.olist.com/v1/freights/woocommerce';
                $headers = array(
                    'Content-Type' => 'application/json',
                    'x-integration-id' => $this->get_option( 'integration_id' ),
                );

                $products = array();

                foreach ($package['contents'] as $item_id => $values) {
                    $product = $values['data'];
                    $products[] = array(
                        'reference' => (string) $product->get_id(),
                        'unit_value' => (float) $product->get_price(),
                        'quantity' => (int) $values['quantity'],
                        'weight' => (float) wc_get_weight( $product->get_weight(), 'kg' ),
                        'length' => (float) wc_get_dimension( $product->get_length(), 'cm' ),
                        'width' => (float) wc_get_dimension( $product->get_width(), 'cm' ),
                        'height' => (float) wc_get_dimension( $product->get_height(), 'cm' ),
                    );
                }

                $data = array(
                    'to' => array('postal_code' => $package['destination']['postcode']),
                    'products' => $products,
                );

                $cache_key = 'olist_envios_quote_' . md5( json_encode( $data ) );
                $cached_response = get_transient( $cache_key );

                if ( false !== $cached_response ) {
                    return $cached_response;
                }

                $body = json_encode( $data );

                $response = wp_remote_post( $url, array(
                    'headers' => $headers,
                    'body'    => $body,
                ) );

                if ( is_wp_error( $response ) ) {
                    return false;
                }

                if ( 201 !== wp_remote_retrieve_response_code( $response ) ) {
                    return false;
                }
                
                $json_data = wp_remote_retrieve_body( $response );
                $result = json_decode( $json_data, true );

                if ( $result ) {
                    set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
                }

                return $result;
            }

			/**
			 * Finds and returns shipping classes and the products with said class.
			 *
			 * @param mixed $package Package of items from cart.
			 * @return array
			 */
			public function find_shipping_classes( $package ) {
				$found_shipping_classes = array();

				foreach ( $package['contents'] as $item_id => $values ) {
					if ( $values['data']->needs_shipping() ) {
						$found_class = $values['data']->get_shipping_class();

						if ( ! isset( $found_shipping_classes[ $found_class ] ) ) {
							$found_shipping_classes[ $found_class ] = array();
						}

						$found_shipping_classes[ $found_class ][ $item_id ] = $values;
					}
				}

				return $found_shipping_classes;
			}

			/**
			 * Add rates from JSON data.
			 *
			 * @param array $data Data with quotes.
			 */
			public function add_rates_from_json( $data ) {
				if ( ! empty( $data['quotes'] ) && is_array( $data['quotes'] ) ) {
					foreach ( $data['quotes'] as $quote ) {
						$rate = array(
                            'id'            => 'olist-envios.' . $quote['carrier_slug'],
                            'label'         => $quote['display_name'],
							'cost'          => $quote['total_cost'],
							'delivery_time' => (string) $quote['delivery_time'],
							'meta_data'     => array(
								'delivery_time' => $quote['delivery_time'],
							),
						);

						$this->add_rate( $rate );
					}
				}
			}

			/**
			 * Our method to initialize our form fields for our stand alone settings page, if needed.
			 *
			 * @return void
			 */
			public function init_form_fields() {
				// Set the form_fields property to an array that will be able to be used by the Settings API to show the fields on the page.
				$this->form_fields = array(
					'integration_id' => array(
						'title'       => __( 'Token de integração', 'olist-envios' ),
						'type'        => 'text',
						'description' => __( 'Token de integração da Olist Envios.', 'olist-envios' ),
						'default'     => '',
						'desc_tip'    => true,
					),
				);
			}

			/**
			 * Our method to initialize our form fields for separate instances.
			 *
			 * @return void
			 */
			private function init_instance_form_fields() {
				// Start the array of fields.
				$this->instance_form_fields = array(
					'integration_id' => array(
						'title'       => __( 'Token de integração', 'olist-envios' ),
						'type'        => 'text',
						'description' => __( 'Token de integração da Olist Envios.', 'olist-envios' ),
						'default'     => '',
						'desc_tip'    => true,
					),
				);
			}
		}
	}
}

/**
 * Format delivery_time with "dias úteis" text
 *
 * @param string $delivery_time The delivery time value.
 * @param WC_Shipping_Rate $rate The shipping rate object.
 * @return string Formatted delivery time.
 */
function olist_envios_format_delivery_time( $delivery_time, $rate ) {
	// Check if this is an Olist Envios shipping method
	if ( ! is_a( $rate, 'WC_Shipping_Rate' ) ) {
		return $delivery_time;
	}

	$method_id = $rate->get_id();
	if ( empty( $method_id ) || strpos( $method_id, 'olist-envios.' ) === false ) {
		return $delivery_time;
	}

	// If delivery_time is empty or not numeric, return as is
	if ( empty( $delivery_time ) || ! is_numeric( $delivery_time ) ) {
		return $delivery_time;
	}

	// Format with "dias úteis"
	$days = absint( $delivery_time );
	return sprintf(
		'%d %s',
		$days,
		_n( 'dia útil', 'dias úteis', $days, 'olist-envios' )
	);
}

/**
 * Set delivery_time on rate after it's created
 *
 * @param WC_Shipping_Rate $rate The shipping rate object.
 * @param array            $args The arguments passed to add_rate.
 * @param WC_Shipping_Method $method The shipping method object.
 * @return WC_Shipping_Rate Modified rate object.
 */
function olist_envios_set_delivery_time_on_rate( $rate, $args, $method ) {
	// Check if this is an Olist Envios shipping method
	if ( ! is_a( $method, 'WC_Olist_Envios_Shipping_Method' ) ) {
		return $rate;
	}

	// Try to get delivery_time from args
	if ( ! empty( $args['delivery_time'] ) ) {
		$rate->set_delivery_time( $args['delivery_time'] );
	} elseif ( ! empty( $args['meta_data']['delivery_time'] ) ) {
		$rate->set_delivery_time( $args['meta_data']['delivery_time'] );
	}

	return $rate;
}

/**
 * Customize shipping method label to display delivery time from meta_data
 *
 * @param string $label The shipping method label.
 * @param object $method The shipping method object (WC_Shipping_Rate).
 * @return string Modified label with delivery time.
 */
function olist_envios_display_delivery_time( $label, $method ) {
	// Check if method object is valid
	if ( ! is_object( $method ) || ! method_exists( $method, 'get_id' ) ) {
		return $label;
	}

	// Get method ID - try both get_id() and direct property access
	$method_id = method_exists( $method, 'get_id' ) ? $method->get_id() : ( isset( $method->id ) ? $method->id : '' );
	
	// Check if this is an Olist Envios shipping method
	if ( empty( $method_id ) || strpos( $method_id, 'olist-envios.' ) === false ) {
		return $label;
	}

	$delivery_time = null;

	// Try to get delivery_time directly from the rate object first (preferred method)
	if ( method_exists( $method, 'get_delivery_time' ) ) {
		$delivery_time = $method->get_delivery_time();
	}

	// If not found or empty, try to get from meta_data
	if ( empty( $delivery_time ) ) {
		$meta_data = array();
		if ( method_exists( $method, 'get_meta_data' ) ) {
			$meta_data = $method->get_meta_data();
			
			if ( ! empty( $meta_data['delivery_time'] ) ) {
				$delivery_time = $meta_data['delivery_time'];
			}
		}
	}

	// If delivery_time exists, add it to the label
	if ( ! empty( $delivery_time ) ) {
		$delivery_time = absint( $delivery_time );
		
		// Format delivery time text
		$delivery_text = sprintf(
			' <small class="olist-delivery-time">(%s %s)</small>',
			esc_html( $delivery_time ),
			_n( 'dia útil', 'dias úteis', $delivery_time, 'olist-envios' )
		);
		
		// Append delivery time to the label
		$label .= $delivery_text;
	}

	return $label;
}

/**
 * Display delivery time after shipping rate using hook
 * This is an alternative method that adds HTML after the label
 *
 * @param object $method The shipping method object (WC_Shipping_Rate).
 * @param int    $index  The shipping method index.
 */
function olist_envios_display_delivery_time_after_rate( $method, $index ) {
	// Check if method object is valid
	if ( ! is_object( $method ) || ! method_exists( $method, 'get_id' ) ) {
		return;
	}

	// Get method ID
	$method_id = method_exists( $method, 'get_id' ) ? $method->get_id() : ( isset( $method->id ) ? $method->id : '' );
	
	// Check if this is an Olist Envios shipping method
	if ( empty( $method_id ) || strpos( $method_id, 'olist-envios.' ) === false ) {
		return;
	}

	$delivery_time = null;

	// Try to get delivery_time directly from the rate object first (preferred method)
	if ( method_exists( $method, 'get_delivery_time' ) ) {
		$delivery_time = $method->get_delivery_time();
	}

	// If not found or empty, try to get from meta_data
	if ( empty( $delivery_time ) ) {
		$meta_data = array();
		if ( method_exists( $method, 'get_meta_data' ) ) {
			$meta_data = $method->get_meta_data();
			
			if ( ! empty( $meta_data['delivery_time'] ) ) {
				$delivery_time = $meta_data['delivery_time'];
			}
		}
	}

	// If delivery_time exists, display it
	if ( ! empty( $delivery_time ) ) {
		$delivery_time = absint( $delivery_time );
		printf(
			'<div class="olist-delivery-time-wrapper"><small class="olist-delivery-time">%s %s</small></div>',
			esc_html( $delivery_time ),
			esc_html( _n( 'dia útil', 'dias úteis', $delivery_time, 'olist-envios' ) )
		);
	}
}
