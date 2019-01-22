<?php

/**
 * Payment plagin for Paysto gateway
 *
 * @cms    Opencart
 * @author     dev@agaxx.ru (Alexey Agafonov)
 * @version    3.3.1
 * @license
 * @copyright  Copyright (c) 2018 Paysto (http://www.paysto.ru)
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Class ControllerExtensionPaymentPaysto
 *
 */
class ControllerExtensionPaymentPaysto extends Controller
{
    
    /**
     * Contant block
     */
    const STATUS_TAX_OFF = 'no_vat';
    const MAX_POS_IN_CHECK = 100;
    const BEGIN_POS_IN_CHECK = 0;
    
    
    /**
     * Index
     *
     * @return mixed
     */
    public function index()
    {
        $x_line_item = '';
        
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['button_back'] = $this->language->get('button_back');
        $data['action'] = 'https://paysto.com/ru/pay/AuthorizeNet';

        $this->load->language('extension/payment/paysto');
        $this->load->model('extension/payment/paysto');

        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $order_products = $this->cart->getProducts();
        
        // Products in order
        $product_amount = 0;

        if ($order_products) {
            foreach ($order_products as $pos => $order_product) {
                $lineArr = array();
                $lineArr[] = '№' . $pos;
                $lineArr[] = substr($order_product['model'], 0, 30);
                $lineArr[] = substr($order_product['name'], 0, 254);
                $lineArr[] = substr($order_product['quantity'], 0, 254);
                $lineArr[] = number_format($order_product['price'], 2, '.',
                    '');
                $lineArr[] = $this->config->get('tax_status') ? $this->getTax($order_product['product_id']) : self::STATUS_TAX_OFF;
                $x_line_item .= implode('<|>', $lineArr) . "0<|>\n";

                $product_amount += $order_product['price'] * $order_product['quantity'];
            }
        }

        // delivery service
        $pos++;

        if ($order['total'] > $product_amount) {
            $lineArr = array();
            $lineArr[] = '№' . $pos;
            $lineArr[] = 'Delivery';
            $lineArr[] = substr($order['shipping_method'], 0, 254);
            $lineArr[] = '1';
            $lineArr[] = number_format($order['total'] - $product_amount, 2, '.',
                '');
            $lineArr[] = self::STATUS_TAX_OFF;
            $x_line_item .= implode('<|>', $lineArr) . "0<|>\n";
        }

        if ($pos > self::MAX_POS_IN_CHECK) {
            $data['error_warning'] = $this->language->get('error_max_pos');
        }
        $data['pos'] = self::BEGIN_POS_IN_CHECK;

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $amount = number_format($order['total'], 2, ".", "");
        $currency = strtoupper($order['currency_code']);
        $order_id = $this->session->data['order_id'];
        $now = time();

        $data['x_login'] = $this->config->get('paysto_x_login');
        $data['x_email'] = $order['email'];
        $data['x_fp_sequence'] = $order_id;
        $data['x_invoice_num'] = $order_id;
        $data['x_amount'] = $amount;
        $data['x_currency_code'] = $currency;
        $data['x_fp_timestamp'] = $now;
        $data['x_description'] = $this->config->get('paysto_description') . ' ' . $order_id;
        $data['x_fp_hash'] = $this->get_x_fp_hash($this->config->get('paysto_x_login'), $order_id,
            $now, $amount, $currency);
        $data['x_relay_response'] = 'TRUE';
        $data['x_relay_url'] = $this->url->link('extension/payment/paysto/callback', '' ,false);

        $data['x_line_item'] = $x_line_item;

        $this->createLog(__METHOD__, $data);

        return $this->load->view('extension/payment/paysto', $data);
    }


    /**
     * Return hash md5 HMAC
     *
     * @param $x_login
     * @param $x_fp_sequence
     * @param $x_fp_timestamp
     * @param $x_amount
     * @param $x_currency_code
     * @return false|string
     */
    private function get_x_fp_hash($x_login, $x_fp_sequence, $x_fp_timestamp, $x_amount, $x_currency_code)
    {
        $arr = [$x_login, $x_fp_sequence, $x_fp_timestamp, $x_amount, $x_currency_code];
        $str = implode('^', $arr);
        return hash_hmac('md5', $str, $this->config->get('payment_paysto_secret_key'));
    }


    /**
     * Return sign with MD5 algoritm
     *
     * @param $x_login
     * @param $x_trans_id
     * @param $x_amount
     * @return string
     */
    private function get_x_MD5_Hash($x_login, $x_trans_id, $x_amount)
    {
        return md5($this->config->get('payment_paysto_secret_key') . $x_login . $x_trans_id . $x_amount);
    }


    /**
     * Logger
     *
     * @param $method
     * @param array $data
     * @param string $text
     * @return bool
     */
    protected function createLog($method, $data = [], $text = '')
    {
        if ($this->config->get('payment_paysto_log')) {
            $this->log->write('---------PAYSTO START LOG---------');
            $this->log->write('---Callback method: ' . $method . '---');
            $this->log->write('---Description: ' . $text . '---');
            $this->log->write($data);
            $this->log->write('---------PAYSTO END LOG----------');
        }
        return true;
    }

    
    /**
     * Fail payment
     *
     * @return bool
     */
    public function fail()
    {
        $this->createLog(__METHOD__, '', 'Payment with Paysto system was failed');
        $this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
        return true;
    }

    
    /**
     * Successful payment
     *
     * @return bool
     */
    public function success()
    {
        $request = $this->request->post;
        if (empty($request)) {
            $request = $this->request->get;
        }
        $order_id = $request["x_invoice_num"];
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if ((int)$order_info["order_status_id"] == (int)$this->config->get('payment_paysto_order_status_id')) {
            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_paysto_order_status_id'), 'Paysto', true);
            $this->createLog(__METHOD__, $request, 'Payment with Paysto was successful!');
            // Reset all cookies and sessions
            unset($this->session->data['shipping_method']);
            unset($this->session->data['shipping_methods']);
            unset($this->session->data['payment_method']);
            unset($this->session->data['payment_methods']);
            unset($this->session->data['guest']);
            unset($this->session->data['comment']);
            unset($this->session->data['order_id']);
            unset($this->session->data['coupon']);
            unset($this->session->data['reward']);
            unset($this->session->data['voucher']);
            unset($this->session->data['vouchers']);
            unset($this->session->data['totals']);

            // Clear the cart
            $this->cart->clear();
            $this->response->redirect($this->url->link('checkout/success', '', 'SSL'));

            return true;
        }
        return false;
    }

    
    /**
     * Callback when check sign
     *
     * @return function [description]
     */
    public function callback()
    {
    
        if (!isset($this->request->post)) {
            exit();
        }
    
        $order_id = $this->request->post["x_invoice_num"];
        $x_trans_id = $this->request->post["x_trans_id"];
        $x_response_code = $this->request->post["x_response_code"];
        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($order_id);
        $amount = number_format($order['total'], 2, '.', '');
        $x_login = $this->config->get('paysto_x_login');
    
        if ($order['order_status_id'] == $this->config->get('paysto_order_status_id')) {
            try {
                $this->success();
                return true;
            } catch (\Exception $exception) {
            
            }
        }
    
        $HTTP_X_FORWARDED_FOR = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '127.0.0.1';
        $HTTP_CF_CONNECTING_IP = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : '127.0.0.1';
        $HTTP_X_REAL_IP = isset($_SERVER['HTTP_X_REAL_IP']) ? $_SERVER['HTTP_X_REAL_IP'] : '127.0.0.1';
        $REMOTE_ADDR = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
        $GEOIP_ADDR = isset($_SERVER['GEOIP_ADDR']) ? $_SERVER['GEOIP_ADDR'] : '127.0.0.1';
    
        if ($this->config->get('payment_paysto_useOnlyList') &&
            ((!in_array($HTTP_X_FORWARDED_FOR, $this->PaystoServers)) &&
                (!in_array($HTTP_CF_CONNECTING_IP, $this->PaystoServers)) &&
                (!in_array($HTTP_X_REAL_IP, $this->PaystoServers)) &&
                (!in_array($REMOTE_ADDR, $this->PaystoServers)) &&
                (!in_array($GEOIP_ADDR, $this->PaystoServers)))) {
            if ($this->session->data['paysto_pay'] = 'success') {
                $this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
                return true;
            } else {
                try {
                    session_destroy();
                } catch (\Exception $exception) {
                    $this->log->write($exception->getMessage());
                }
            }
        }
    
        if (isset($this->request->post['x_MD5_Hash'])) {
            $x_MD5_Hash = $this->request->post['x_MD5_Hash'];
            if ($x_MD5_Hash == $this->get_x_MD5_Hash($x_login, $x_trans_id, $amount) && $x_response_code == 1) {
                if ($order['order_status_id'] == 0) {
                    try {
                        $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_paysto_order_status_id'), 'Payment with Paysto');
                    } catch
                    (\Exception $exception) {
                        $this->log->write($exception->getMessage());
                        exit();
                    }
                    exit();
                }
                if ($order['order_status_id'] != $this->config->get('paysto_order_status_id') || $x_response_code != 1) {
                    try {
                        $this->model_checkout_order->addOrderHistory($order_id,
                            $this->config->get('paysto_order_status_id'), 'Paysto', true);
                    } catch (\Exception $exception) {
                        $this->log->write($exception->getMessage());
                        exit();
                    }
                    exit();
                }
            } else {
                $this->log->write("Paysto sign is not correct or other error happen!");
            }
        }

    }
    
    
    /**
     * Get product tax information
     *
     * @param $product_id
     * @return mixed
     */
    protected function getTax($product_id)
    {
        $this->load->model('catalog/product');
        $product_info = $this->model_catalog_product->getProduct($product_id);
        $tax_rule_id = 3;

        foreach ($this->config->get('payment_paysto_classes') as $i => $tax_rule) {
            if ($tax_rule['paysto_nalog'] == $product_info['tax_class_id']) {
                $tax_rule_id = $tax_rule['paysto_tax_rule'];
            }
        }

        $tax_rules = array(
            array(
                'id' => 0,
                'name' => 'Without VAT',
            ),
            array(
                'id' => 1,
                'name' => 'With VAT',
            ),
        );
        return $tax_rules[$tax_rule_id]['name'];
    }
    
    
    /**
     * Logger function to
     *
     * @param $var
     * @param string $text
     */
    public function logger($var, $text = '')
    {
        // File name
        $loggerFile = __DIR__ . '/logger.log';
        if (is_object($var) || is_array($var)) {
            $var = (string)print_r($var, true);
        } else {
            $var = (string)$var;
        }
        $string = date("Y-m-d H:i:s") . " - " . $text . ' - ' . $var . "\n";
        file_put_contents($loggerFile, $string, FILE_APPEND);
    }
}
