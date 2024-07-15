<?php
/*
Plugin Name: WooCommerce Orange Money Guinée
Plugin URI: https://amtechguinee.com/
Description: Orange Money Payment for WooCommerce.
Version: 1.0.0
Author: Amtech Guinée
Author URI: https://amtechguinee.com/
*/

if (!defined('ABSPATH')) {
    exit; // Quitte si accédé directement.
}

// Assurez-vous que WooCommerce est actif
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    // Ajoute le mode de paiement Orange Money aux méthodes de paiement disponibles
    function woocommerce_add_orange_money_gateway($methods) {
        $methods[] = 'WC_Orange_Money_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_orange_money_gateway');

    // Initialisation de la classe du mode de paiement Orange Money
    add_action('plugins_loaded', 'woocommerce_orange_money_init', 11);

    function woocommerce_orange_money_init() {
        if (class_exists('WC_Payment_Gateway')) {

            class WC_Orange_Money_Gateway extends WC_Payment_Gateway {

                public function __construct() {
                    $this->id = 'orange_money';
                    $this->icon = apply_filters('woocommerce_orange_money_icon', '');
                    $this->has_fields = false;
                    $this->method_title = __('Orange Money', 'woocommerce');
                    $this->method_description = __('Permet les paiements avec Orange Money.', 'woocommerce');

                    // Charge les paramètres.
                    $this->init_form_fields();
                    $this->init_settings();

                    // Définit les variables utilisateur.
                    $this->title = $this->get_option('title');
                    $this->description = $this->get_option('description');
                    $this->api_key = $this->get_option('api_key');
                    $this->api_secret = $this->get_option('api_secret');
                    $this->sandbox = 'yes' === $this->get_option('sandbox');

                    // Actions.
                    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                    add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

                    // Ecouteur de paiement / hook API
                    add_action('woocommerce_api_wc_' . $this->id, array($this, 'check_ipn_response'));
                }

                // Initialise les champs de formulaire des paramètres de la passerelle
                public function init_form_fields() {
                    $this->form_fields = array(
                        'enabled' => array(
                            'title'       => __('Activer/Désactiver', 'woocommerce'),
                            'type'        => 'checkbox',
                            'label'       => __('Activer le paiement Orange Money', 'woocommerce'),
                            'default'     => 'yes'
                        ),
                        'title' => array(
                            'title'       => __('Titre', 'woocommerce'),
                            'type'        => 'text',
                            'description' => __('Ce titre sera affiché à l\'utilisateur lors du paiement.', 'woocommerce'),
                            'default'     => __('Orange Money', 'woocommerce'),
                            'desc_tip'    => true,
                        ),
                        'description' => array(
                            'title'       => __('Description', 'woocommerce'),
                            'type'        => 'textarea',
                            'description' => __('Cette description sera affichée à l\'utilisateur lors du paiement.', 'woocommerce'),
                            'default'     => __('Payez avec Orange Money', 'woocommerce'),
                        ),
                        'api_key' => array(
                            'title'       => __('Clé API', 'woocommerce'),
                            'type'        => 'text',
                            'description' => __('Votre clé API Orange Money', 'woocommerce'),
                            'default'     => '',
                        ),
                        'api_secret' => array(
                            'title'       => __('Secret API', 'woocommerce'),
                            'type'        => 'text',
                            'description' => __('Votre secret API Orange Money', 'woocommerce'),
                            'default'     => '',
                        ),
                        'sandbox' => array(
                            'title'       => __('Mode Sandbox', 'woocommerce'),
                            'type'        => 'checkbox',
                            'label'       => __('Activer le mode Sandbox', 'woocommerce'),
                            'default'     => 'yes',
                            'description' => __('Place la passerelle de paiement en mode sandbox en utilisant les clés API de test.', 'woocommerce'),
                        )
                    );
                }

                // Traite le paiement et retourne le résultat
                public function process_payment($order_id) {
                    $order = wc_get_order($order_id);

                    // Effectue la requête API Orange Money ici
                    $response = wp_remote_post('https://api.orange-money.com/payment', array(
                        'method'    => 'POST',
                        'body'      => json_encode(array(
                            'api_key'    => $this->api_key,
                            'api_secret' => $this->api_secret,
                            'amount'     => $order->get_total(),
                            'currency'   => get_woocommerce_currency(),
                            'order_id'   => $order->get_id(),
                            'callback_url' => $this->get_return_url($order)
                        )),
                        'headers' => array(
                            'Content-Type' => 'application/json',
                        ),
                    ));

                    if (is_wp_error($response)) {
                        wc_add_notice(__('Erreur de paiement:', 'woocommerce') . ' ' . $response->get_error_message(), 'error');
                        return;
                    }

                    $body = json_decode($response['body'], true);

                    if ($body['status'] == 'success') {
                        // Marque comme en cours (paiement réussi)
                        $order->payment_complete();
                        $order->reduce_order_stock();

                        // Vide le panier
                        WC()->cart->empty_cart();

                        // Retourne l'URL de redirection vers la page de remerciement
                        return array(
                            'result'   => 'success',
                            'redirect' => $this->get_return_url($order)
                        );
                    } else {
                        wc_add_notice(__('Erreur de paiement:', 'woocommerce') . ' ' . $body['message'], 'error');
                        return;
                    }
                }

                // Vérifie la réponse API d'Orange Money
                public function check_ipn_response() {
                    // Traite la réponse API d'Orange Money ici
                    $body = file_get_contents('php://input');
                    $data = json_decode($body, true);

                    if (isset($data['order_id']) && isset($data['status'])) {
                        $order = wc_get_order($data['order_id']);

                        if ($data['status'] == 'success') {
                            $order->payment_complete();
                        } else {
                            $order->update_status('failed', __('Le paiement Orange Money a échoué', 'woocommerce'));
                        }
                    }

                    // Répond à l'IPN d'Orange Money
                    header('HTTP/1.1 200 OK');
                }
            }
        }
    }
}
?>
