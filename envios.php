<?php
/**
 * Plugin Name: Olist Envios para WooCommerce
 * Plugin URI:  https://envios.olist.com/integracao/woocommerce
 * Description: Integração oficial de fretes Envios da Olist. Calcula prazos e preços diretamente no checkout.
 * Version:     1.0.3
 * Author:      Olist
 * Author URI:  https://olist.com/envios
 * License:     GPLv2 or later
 * Text Domain: olist-envios
 * Domain Path: /languages
 *
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

// URL Base da API (Definida como constante para facilitar alterações)
define( 'OLIST_API_BASE_URL', 'https://envios-api.olist.com' );

/**
 * Declaração de compatibilidade com HPOS (High-Performance Order Storage)
 * Necessário para lojas novas com WooCommerce 8.0+
 */
add_action( 'before_woocommerce_init', 'olist_envios_hpos_compatibility' );

function olist_envios_hpos_compatibility() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
}

/**
 * Hook de Desinstalação
 */
register_uninstall_hook( __FILE__, array( 'Olist_Envios_Uninstaller', 'uninstall' ) );

class Olist_Envios_Uninstaller {
    public static function uninstall() {
        $settings = get_option( 'woocommerce_olist_envios_settings' );
        $token    = isset( $settings['integration_token'] ) ? $settings['integration_token'] : '';

        if ( ! empty( $token ) ) {
            $url = OLIST_API_BASE_URL . '/v1/webhook/ecommerce/woocommerce/app-uninstall';
            
            wp_remote_post( $url, array(
                'blocking'    => false,
                'headers'     => array(
                    'Content-Type'     => 'application/json',
                    'x-integration-id' => $token
                ),
                'body'        => json_encode( array(
                    'timestamp' => time(),
                    'action'    => 'uninstall'
                ) ),
                'timeout'     => 5,
            ));
        }
    }
}

/**
 * Inicialização do Plugin
 * Usa 'plugins_loaded' para garantir que o WooCommerce já esteja carregado
 */
add_action( 'plugins_loaded', 'olist_envios_boot' );

function olist_envios_boot() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    // Registra a classe do método de entrega
    add_action( 'woocommerce_shipping_init', 'olist_envios_shipping_init' );
    
    // Adiciona o método à lista do WooCommerce
    add_filter( 'woocommerce_shipping_methods', 'add_olist_envios_method' );
}

function add_olist_envios_method( $methods ) {
    $methods['olist_envios'] = 'WC_Olist_Shipping_Method';
    return $methods;
}

function olist_envios_shipping_init() {
    
    if ( ! class_exists( 'WC_Olist_Shipping_Method' ) ) {
        
        class WC_Olist_Shipping_Method extends WC_Shipping_Method {

            /**
             * Token de integração
             * @var string
             */
            public $integration_token;

            public function __construct() {
                $this->id                 = 'olist_envios';
                $this->method_title       = __( 'Olist Envios', 'olist-envios' );
                $this->method_description = __( 'Método de entrega para cálculo via API Olist.', 'olist-envios' );

                // Suportes essenciais para aparecer nas Zonas de Entrega
                $this->supports = array(
                    'shipping-zones',
                    'instance-settings',
                    'settings',
                );

                $this->init_form_fields();
                $this->init_settings();

                $this->enabled           = $this->get_option( 'enabled' );
                $this->title             = $this->get_option( 'title' );
                $this->integration_token = $this->get_option( 'integration_token' );

                add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
            }

            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title'   => __( 'Habilitar', 'olist-envios' ),
                        'type'    => 'checkbox',
                        'label'   => __( 'Habilitar este método de entrega', 'olist-envios' ),
                        'default' => 'yes',
                    ),
                    'title' => array(
                        'title'       => __( 'Nome no Checkout', 'olist-envios' ),
                        'type'        => 'text',
                        'description' => __( 'Nome que o cliente verá durante a compra.', 'olist-envios' ),
                        'default'     => __( 'Envios da Olist', 'olist-envios' ),
                    ),
                    'integration_token' => array(
                        'title'       => __( 'Token de Integração', 'olist-envios' ),
                        'type'        => 'password',
                        'description' => __( 'Insira o token de integração fornecido pela Olist.', 'olist-envios' ),
                        'default'     => '',
                        'placeholder' => 'Ex: 0195aef7-5f80-76af-b889...',
                    ),
                    'debug' => array(
                        'title'       => __( 'Modo Debug', 'olist-envios' ),
                        'label'       => __( 'Ativar log de requisições', 'olist-envios' ),
                        'type'        => 'checkbox',
                        'default'     => 'no',
                        'description' => __( 'Salva logs em WooCommerce > Status > Logs.', 'olist-envios' ),
                    ),
                );
            }

            public function calculate_shipping( $package = array() ) {
                if ( empty( $this->integration_token ) ) {
                    return;
                }

                $destination_postcode = preg_replace( '/[^0-9]/', '', $package['destination']['postcode'] );
                
                if ( empty( $destination_postcode ) ) {
                    return;
                }

                $products_payload = array();
                
                foreach ( $package['contents'] as $item_id => $values ) {
                    $product = $values['data'];
                    $qty     = $values['quantity'];

                    if ( $values['quantity'] > 0 && $product->needs_shipping() ) {
                        
                        $height = wc_get_dimension( $product->get_height(), 'cm' );
                        $width  = wc_get_dimension( $product->get_width(), 'cm' );
                        $length = wc_get_dimension( $product->get_length(), 'cm' );
                        $weight = wc_get_weight( $product->get_weight(), 'kg' );
                        $price  = $product->get_price();

                        $height = $height ? $height : 1;
                        $width  = $width  ? $width  : 1;
                        $length = $length ? $length : 1;
                        $weight = $weight ? $weight : 0.1;

                        $products_payload[] = array(
                            'unit_value' => (float) $price,
                            'quantity'   => (int) $qty,
                            'height'     => (float) $height,
                            'length'     => (float) $length,
                            'width'      => (float) $width,
                            'weight'     => (float) $weight
                        );
                    }
                }

                if ( empty( $products_payload ) ) {
                    return;
                }

                $payload = array(
                    'to' => array(
                        'postal_code' => (int) $destination_postcode
                    ),
                    'products' => $products_payload
                );

                $response = wp_remote_post( OLIST_API_BASE_URL . '/v1/freights/woocommerce', array(
                    'method'    => 'POST',
                    'timeout'   => 10,
                    'headers'   => array(
                        'Content-Type'     => 'application/json',
                        'x-integration-id' => $this->integration_token
                    ),
                    'body'      => json_encode( $payload )
                ));

                if ( 'yes' === $this->get_option( 'debug' ) ) {
                    $logger = wc_get_logger();
                    $logger->debug( 'Olist Request: ' . print_r( $payload, true ), array( 'source' => 'olist_envios' ) );
                    $logger->debug( 'Olist Response: ' . print_r( $response, true ), array( 'source' => 'olist_envios' ) );
                }

                if ( is_wp_error( $response ) ) {
                    return;
                }

                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $code = wp_remote_retrieve_response_code( $response );

                if ( $code !== 200 || empty( $body ) ) {
                    return;
                }

                $quotes = isset($body['quotes']) ? $body['quotes'] : [];

                foreach ( $quotes as $quote ) {
                    if ( ! isset( $quote['total_cost'] ) ) continue;

                    $carrier_slug = isset($quote['carrier_slug']) ? $quote['carrier_slug'] : sanitize_title($quote['carrier_name']);
                    $method_id    = $this->id . '_' . $carrier_slug;

                    $label = isset($quote['display_name']) ? $quote['display_name'] : 'Olist Envios';
                    
                    if ( isset( $quote['delivery_time'] ) ) {
                        $label .= ' (' . $quote['delivery_time'] . ' dias úteis)';
                    }

                    $rate = array(
                        'id'        => $method_id,
                        'label'     => $label,
                        'cost'      => $quote['total_cost'],
                        'meta_data' => array(
                            'carrier_name'  => isset($quote['carrier_name']) ? $quote['carrier_name'] : '',
                            'delivery_days' => isset($quote['delivery_time']) ? $quote['delivery_time'] : ''
                        )
                    );

                    $this->add_rate( $rate );
                }
            }
        }
    }
}
