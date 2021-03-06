<?php

class Magento5_Latipay_PaymentController extends Mage_Core_Controller_Front_Action
{
    protected $_transactionDetailKeys = array(
        'signature',
        'order_id',
        'merchant_reference',
        'currency',
        'amount',
        'payment_method',
        'status',
    );

    public function redirectAction()
    {
        $apiKey = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/latipay/api_key'));
        $responseData = Mage::helper('latipay')->getTransactionData();
        if ($responseData && !empty($responseData['host_url']) && !empty($responseData['nonce'])) {
            $responseSignature = hash_hmac('sha256', $responseData['nonce'] . $responseData['host_url'], $apiKey);
            if ($responseSignature == $responseData['signature']) {
                $redirectUrl = $responseData['host_url'] . '/' . $responseData['nonce'];
                $this->_redirectUrl($redirectUrl);
                return;
            }
        }

        if ($responseData && !empty($responseData['message'])) {
            die($responseData['message']);
        }

        die(__('Transaction failure'));
    }

    public function returnAction()
    {
        $this->_responseProcessing('return');
    }

    public function callbackAction()
    {
        $this->_responseProcessing('callback');
    }

    public function _responseProcessing($type = 'return')
    {
        $apiKey = Mage::helper('core')->decrypt(Mage::getStoreConfig('payment/latipay/api_key'));

        $signature = $this->getRequest()->getParam('signature');
        $transactionId = $this->getRequest()->getParam('order_id');
        $merchantReference = $this->getRequest()->getParam('merchant_reference');
        $currency = $this->getRequest()->getParam('currency');
        $amount = $this->getRequest()->getParam('amount');
        $paymentMethod = $this->getRequest()->getParam('payment_method');
        $status = $this->getRequest()->getParam('status');

        $signText = $merchantReference . $paymentMethod . $status . $currency . $amount;
        $callbackSignature = hash_hmac('sha256', $signText, $apiKey);
        $postData = $this->getRequest()->getParams();

        if ($signature == $callbackSignature) {
            if ($status == "paid") {
                $merchantOrderId = substr($merchantReference, 0, strripos($merchantReference, '_'));
                $order = Mage::getModel('sales/order')->loadByIncrementId($merchantOrderId);

                if ($order->getStatus() == Mage_Sales_Model_Order::STATE_PROCESSING) {
                    if ($type == 'return') {
                        $this->_redirect('checkout/onepage/success', array('_secure' => false));
                        return;
                    } else {
                        die('sent');
                    }
                }

                $payment = $order->getPayment();
                $payment->setTransactionId($transactionId);

                foreach ($this->_transactionDetailKeys as $key) {
                    isset($postData[$key]) and $payment->setTransactionAdditionalInfo($key, $postData[$key]);
                }

                if ($order->canInvoice()) {
                    $invoice = $order->prepareInvoice();
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                    $invoice->register();
                    $invoice->save();
                    $order->addStatusHistoryComment(Mage::helper('core')->__('Invoice #%s created', $invoice->getIncrementId()), false)->setIsCustomerNotified(false);
                }

                $order->addStatusHistoryComment(Mage::helper('core')->__('Payment successful. Latipay Response: ' . json_encode($postData)), false)->setIsCustomerNotified(false);

                try {
                    $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                    $status = true;
                    $order->setState($state, $status);
                    $order->save();
                    Mage::getSingleton('checkout/session')->unsQuoteId();

                    if ($type == 'return') {
                        $this->_redirect('checkout/onepage/success', array('_secure' => false));
                    } else {
                        die('sent');
                    }
                } catch (Exception $e) {
                }

            } else {
                if ($type == 'return') {
                    $this->_redirect('checkout/onepage/error', array('_secure' => true));
                } else {
                    die('fail');
                }
            }
        } else {
            if ($type == 'return') {
                $this->_redirect('checkout/onepage/error', array('_secure' => true));
            } else {
                die('fail');
            }
        }

    }

    public function cancelAction()
    {
        $session = Mage::getSingleton('checkout/session');
        if ($session->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if ($order->getId()) {
                $quote = Mage::getModel('sales/quote')
                    ->load($order->getQuoteId());
                //Return quote
                if ($quote->getId()) {
                    $quote->setIsActive(1)
                        ->setReservedOrderId(NULL)
                        ->save();
                    $session->replaceQuote($quote);
                }

                //Unset data
                $session->unsLastRealOrderId();
            }
        }

        return $this->getResponse()->setRedirect(Mage::getUrl('checkout/onepage'));
    }

    public function debugInfoAction()
    {
        $is_debug = Mage::getStoreConfig('payment/latipay/is_debug');
        if ($is_debug && $is_debug == 1) {
            echo '<br>';
            $info = Mage::getStoreConfig('payment/latipay');
            echo 'Latipay config :';
            echo '<br><br>';
            echo json_encode($info) . PHP_EOL;
            echo '<br><br>';

            $info = json_encode(Mage::getConfig()->getNode('modules')->children()->Magento5_Latipay->version);
            $version = json_decode($info, true)[0];
            echo 'Latipay version : ' . $version . PHP_EOL;
            echo '<br>';

            echo '<br>';
            echo phpinfo();
        } else {
            die('access denied');
        }
    }

}
