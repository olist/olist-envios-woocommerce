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
				do_action( 'woocommerce_' . $this->id . '_shipping_add_rate', $this, $rate );
			}

            private function get_shipping_quote($package) {
                // $url = 'https://envios-api.olist.com/v1/freights/woocommerce';
                $url = 'https://zhjmnwls-5000.brs.devtunnels.ms/v1/freights/woocommerce';
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
                        'weight' => (float) $product->get_weight(),
                        'length' => (float) $product->get_length(),
                        'width' => (float) $product->get_width(),
                        'height' => (float) $product->get_height(),
                    );
                }

                $data = array(
                    'to' => array('postal_code' => $package['destination']['postcode']),
                    'products' => $products,
                );

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
                return json_decode( $json_data, true );
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
                            'id'        => 'olist-envios.' . $quote['carrier_name'],
                            'label'     => sprintf('%s - %s dias', $quote['display_name'], $quote['delivery_time'])  ,
							'cost'      => $quote['total_cost'],
							'meta_data' => array(
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
