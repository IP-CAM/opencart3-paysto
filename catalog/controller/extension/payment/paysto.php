<?php

class ControllerExtensionPaymentPaysto extends Controller
{
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

        ///delivery service
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
     * Неуспешный платеж сообщение пользователю
     * @return [type] [description]
     */
    public function fail()
    {
        $this->createLog(__METHOD__, '', 'Платеж не выполнен');
        $this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
        return true;
    }


    /**
     * Успешный платеж сообщение пользователю
     * @return [type] [description]
     */
    public function success()
    {

        $request = $this->request->post;

        if (empty($request)) {
            $request = $this->request->get;
        }

        $order_id = $request["LMI_PAYMENT_NO"];
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if ((int)$order_info["order_status_id"] == (int)$this->config->get('payment_paysto_order_status_id')) {
            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_paysto_order_status_id'), 'Paysto', true);
            $this->createLog(__METHOD__, $request, 'Платеж успешно завершен');

            // Сброс всех cookies и сессий
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

            // очищаем карточку
            $this->cart->clear();

            $this->response->redirect($this->url->link('checkout/success', '', 'SSL'));

            return true;
        }

        return false;
    }

    /**
     * Callback № 1 где проверяется подпись
     * @return function [description]
     */
    public function callback()
    {
        if (isset($this->request->post)) {
            $this->createLog(__METHOD__, $this->request->post, 'Данные с сервиса PAYSTO');
        }

        $order_id = $this->request->post["LMI_PAYMENT_NO"];
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        $amount = number_format($order_info['total'], 2, '.', '');
        $currency = $order_info['currency_code'];
        
        $x_login = $this->config->get('payment_paysto_x_login');

        // Если у нас есть предварительные запрос
        if (isset($this->request->post['LMI_PREREQUEST'])) {
            if ($this->request->post['LMI_MERCHANT_ID'] == $x_login && $this->request->post['LMI_PAYMENT_AMOUNT'] == $amount) {
                echo 'YES';
                exit;
            } else {
                echo 'FAIL';
                exit;
            }
        }

        // Проверка на совпадение ID мерчанта если нет уходим
        if ($x_login != $this->request->post['LMI_MERCHANT_ID']) {
            echo 'FAIL';
            exit;
        }

        // Проверка на валюту и сумму платежа
        if (($currency != $this->request->post['LMI_PAID_CURRENCY']) && ($amount != $this->request->post['LMI_PAYMENT_AMOUNT'])){
            echo 'FAIL';
            exit;
        }

        // Самая важная проверка HASH 
        if (isset($this->request->post['LMI_HASH'])) {
            $lmi_hash = $this->request->post['LMI_HASH'];
            $lmi_sign = $this->request->post['SIGN'];
            $hash = $this->getHash($this->request->post);
            $sign = $this->getSign($this->request->post);
            if (($lmi_hash == $hash) && ($lmi_sign == $sign)) {
                if ($order_info['order_status_id'] == 0) {
                    $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_paysto_order_status_id'), 'Оплачено через Paysto');
                    exit;
                }
                if ($order_info['order_status_id'] != $this->config->get('payment_paysto_order_status_id')) {
                    $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_paysto_order_status_id'), 'Paysto', true);
                }
            } else {
                $this->log->write("Paysto sign or hash is not correct!");
            }
        }

    }

    /**
     * Получение подписи для дополнительной безопасности
     * @param  [type] $request фактически post запрос
     * @return [type]          [description]
     */
    public function getSign($request)
    {
        $hash_alg = $this->config->get('payment_paysto_hash_alg');
        $secret_key = htmlspecialchars_decode($this->config->get('payment_paysto_secret_key'));
        $plain_sign = $request['LMI_MERCHANT_ID'] . $request['LMI_PAYMENT_NO'] . $request['LMI_PAID_AMOUNT'] . $request['LMI_PAID_CURRENCY'] . $secret_key;
        return base64_encode(hash($hash_alg, $plain_sign, true));
    }

    /**
     * Получаем HASH
     * @param  [type] $request фактически post запрос
     * @return [type]          [description]
     */
    public function getHash($request)
    {
        $hash_alg = $this->config->get('payment_paysto_hash_alg');
        $SECRET = htmlspecialchars_decode($this->config->get('payment_paysto_secret_key'));
        // Получаем ID продавца не из POST запроса, а из модуля (исключаем, тем самым его подмену)
        $LMI_MERCHANT_ID = $request['LMI_MERCHANT_ID'];
        //Получили номер заказа очень нам он нужен, смотрите ниже, что мы с ним будем вытворять
        $LMI_PAYMENT_NO = $request['LMI_PAYMENT_NO'];
        //Номер платежа в системе Paysto
        $LMI_SYS_PAYMENT_ID = $request['LMI_SYS_PAYMENT_ID'];
        //Дата платежа
        $LMI_SYS_PAYMENT_DATE = $request['LMI_SYS_PAYMENT_DATE'];
        $LMI_PAYMENT_AMOUNT = $request['LMI_PAYMENT_AMOUNT'];
        //Теперь получаем валюту заказа, то что была в заказе
        $LMI_CURRENCY =   $request['LMI_CURRENCY'];
        $LMI_PAID_AMOUNT = $request['LMI_PAID_AMOUNT'];
        $LMI_PAID_CURRENCY = $request['LMI_PAID_CURRENCY'];
        $LMI_PAYMENT_SYSTEM = $request['LMI_PAYMENT_SYSTEM'];
        $LMI_SIM_MODE = $request['LMI_SIM_MODE'];
        $string = $LMI_MERCHANT_ID . ";" . $LMI_PAYMENT_NO . ";" . $LMI_SYS_PAYMENT_ID . ";" . $LMI_SYS_PAYMENT_DATE . ";" . $LMI_PAYMENT_AMOUNT . ";" . $LMI_CURRENCY . ";" . $LMI_PAID_AMOUNT . ";" . $LMI_PAID_CURRENCY . ";" . $LMI_PAYMENT_SYSTEM . ";" . $LMI_SIM_MODE . ";" . $SECRET;
        $hash = base64_encode(hash($hash_alg, $string, true));
        return $hash;
    }

    /**
     * Получение налоговой информации по продукту
     * @param  [type] $product_id  id продукта
     * @return [type]             [description]
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
                'name' => 'vat18',
            ),
            array(
                'id' => 1,
                'name' => 'vat10',
            ),
            array(
                'id' => 2,
                'name' => 'vat0',
            ),
            array(
                'id' => 3,
                'name' => 'no_vat',
            ),
            array(
                'id' => 4,
                'name' => 'vat118',
            ),
            array(
                'id' => 5,
                'name' => 'vat110',
            ),
        );
        return $tax_rules[$tax_rule_id]['name'];
    }

    /**
     * Моя любимая функция Logger
     * @param  [type] $var  [description]
     * @param  string $text [description]
     * @return [type]       [description]
     */
    public function logger($var, $text = '')
    {
        // Название файла
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
