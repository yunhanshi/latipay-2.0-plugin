<?php
/**
 * Latipay V2
 */
include_once(DIR_SYSTEM . 'library/latipay2/lib/Latipay.php');
require_once(DIR_SYSTEM . 'library/latipay2/lib/IP.php');

class ControllerPaymentLatipay2 extends Controller
{
    public function index()
    {
        $this->language->load('payment/latipay2');
        $data['text_payment_method'] = $this->language->get('text_payment_method');
        $data['text_payment_method_alert'] = $this->language->get('text_payment_method_alert');

        $data['text_alipay'] = $this->language->get('text_alipay');
        $data['text_wechat'] = $this->language->get('text_wechat');
        $data['text_moneymore'] = $this->language->get('text_moneymore');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_loading'] = $this->language->get('text_loading');
        $data['continue'] = $this->url->link('checkout/success');
        $data['latipay_error'] = '';

        $wallet_id = '';
        if (isset($this->session->data['currency'])) {
            $currency = $this->session->data['currency'];

            if ($currency == 'NZD') {
                $wallet_id = trim($this->config->get('latipay2_wallet_id_nzd'));
            } else if ($currency == 'AUD') {
                $wallet_id = trim($this->config->get('latipay2_wallet_id_aud'));
            } else if ($currency == 'CNY') {
                $wallet_id = trim($this->config->get('latipay2_wallet_id_cny'));
            }
        }

        if (!$wallet_id) {
            $data['latipay_error'] = "Latipay Error! Please try later.(Latipay wallet_id Not found. currency: {$this->session->data['currency']})";
            return $this->load->view('default/template/payment/latipay2.tpl', $data);
        }

        $user_id = trim($this->config->get('latipay2_user_id'));
        $api_key = trim($this->config->get('latipay2_api_key'));

        $_prehash = $wallet_id . $user_id;
        $signature = hash_hmac('sha256', $_prehash, $api_key);
        $url = trim($this->config->get('latipay2_payment_method_url')) . $wallet_id . "?user_id=" . $user_id . "&signature=" . $signature;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $output = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $data['latipay_error'] = "Latipay Error! Please try later. (api request error:{$error})";
            return $this->load->view('default/template/payment/latipay2.tpl', $data);
        }

        $wallet = '';
        $apiData = json_decode($output, true);
        if (is_array($apiData) && isset($apiData['code']) && ($apiData['code'] === 0)) {
            $wallet = $apiData['payment_method'];
        }

        if (!$wallet) {
            $wallet = 'Wechat,Alipay';
        }

        $paymentMethodNames = [
            'alipay' => $this->language->get('text_alipay'),
            'wechat' => $this->language->get('text_wechat'),
            'moneymore' => $this->language->get('text_moneymore'),
        ];

        $NotRequiredPaymentMethod = ['latipay', 'onlinebank', 'polipay'];

        $walletList = explode(',', $wallet);
        $select_array = array();
        foreach ($walletList as $key => $value) {
            if (in_array(strtolower($value), $NotRequiredPaymentMethod)) {
                continue;
            }

            $select_array[] = array(
                'name' => $name = isset($paymentMethodNames[strtolower($value)]) ? $paymentMethodNames[strtolower($value)] : $value,
                'value' => strtolower($value),
            );
        }

        $data['select_array'] = $select_array;

        return $this->load->view('default/template/payment/latipay2.tpl', $data);
    }

    public function confirm()
    {
        $json = array();
        $order_id = $this->session->data['order_id'];

        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        $payment_method = $this->request->post['payment_method'];
        $gateway = trim($this->config->get('latipay2_gateway_url'));
        $url_return = HTTP_SERVER . "latipay2/url.php";
        $url_notify = HTTP_SERVER . "latipay2/backUrl.php";
        $api_key = trim($this->config->get('latipay2_api_key'));
        $user_id = trim($this->config->get('latipay2_user_id'));

        $wallet_id = '';
        if (isset($this->session->data['currency'])) {
            $currency = $this->session->data['currency'];

            if ($currency == 'NZD') {
                $wallet_id = trim($this->config->get('latipay2_wallet_id_nzd'));
            } else if ($currency == 'AUD') {
                $wallet_id = trim($this->config->get('latipay2_wallet_id_aud'));
            } else if ($currency == 'CNY') {
                $wallet_id = trim($this->config->get('latipay2_wallet_id_cny'));
            }
        }
        if (!$wallet_id) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(array('error' => "Error! Currency is invalid. Please try later. (currency : {$this->session->data['currency']})")));
            return;
        }

        $total = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);

        $post_data = array(
            'user_id' => $user_id,
            'wallet_id' => $wallet_id,
            'amount' => $total,
            'payment_method' => $payment_method,
            'return_url' => $url_return,
            'callback_url' => $url_notify,
            'backPage_url' => HTTP_SERVER . "index.php?route=checkout/checkout",
            'merchant_reference' => $order_id . '_' . uniqid(),
            'ip' => IP::clientIP(),
            'product_name' => 'Order #' . $order_id,
            'version' => '2.0',
            'present_qr' => '1',
        );

        ksort($post_data);
        $item = array();
        foreach ($post_data as $key => $value) {
            $item[] = $key . "=" . $value;
        }
        $_prehash =  join("&", $item);
        $signature = hash_hmac('sha256', $_prehash . $api_key, $api_key);
        $post_data['signature'] = $signature;

        $latipay = new Latipay($gateway);
        try {
            $payment = $latipay->createPayment($post_data);
            if ($payment['host_url'] != '') {
                $response_signature = hash_hmac('sha256', $payment['nonce'] . $payment['host_url'], $api_key);
                if ($response_signature == $payment['signature']) {
                    $redirect_url = $payment['host_url'] . '/' . $payment['nonce'];
                    $json['success'] = 'ok';
                    $json['redirect_url'] = $redirect_url;
                }
            } else {
                $json['error'] = $payment['message'];
            }
        } catch (\Exception $e) {
            $json['error'] = 'error';
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    //send email
    public function callback()
    {
        if (!isset($this->request->post['order_id']) || !isset($this->request->post['order_status_id'])) {
            return false;
        }

        $this->load->model('checkout/order_latipay2');
        $order_id = $this->request->post['order_id'];
        $order_status_id = $this->request->post['order_status_id'];
        $latipay_order_id = $this->request->post['latipay_order_id'];

        $sql = $this->db->query("SELECT if_email_latipay2 FROM " . DB_PREFIX . "order WHERE order_id = '" . $order_id . "' LIMIT 1 ");
        if ($sql->num_rows) {
            if ($sql->row['if_email_latipay2'] == '0') {

                $this->db->query("UPDATE " . DB_PREFIX . "order SET if_email_latipay2 = '1' WHERE order_id = '" . $order_id . "' ");
                $this->model_checkout_order_latipay2->addOrderHistory($order_id, $order_status_id, 'Latipay payment complete. order_id:' . $latipay_order_id, 1);
            }
        }
    }
}
