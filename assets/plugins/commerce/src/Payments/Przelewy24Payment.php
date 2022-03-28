<?php

namespace Commerce\Payments;

class Przelewy24Payment extends Payment
{
    public function __construct(\DocumentParser $modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('przelewy24');
    }

    public function getMarkup()
    {
        if (empty($this->getSetting('posId')) || empty($this->getSetting('secretId')) || empty($this->getSetting('merchantId'))) {
            return '<span class="error" style="color: red;">' . $this->lang['przelewy24.error.empty_client_credentials'] . '</span>';
        }

        return '';
    }

    public function getPaymentLink()
    {
        $processor = $this->modx->commerce->loadProcessor();
        $order = $processor->getOrder();
        $payment = $this->createPayment($order['id'], $order['amount']);
        $data = [
            'merchantId'  => (int)$this->getSetting('merchantId'),
            'posId'       => (int)$this->getSetting('posId'),
            'sessionId'   => $payment['hash'],
            'amount'      => (int) ($payment['amount'] * 100),
            'currency'    => $order['currency'],
            'description' => ci()->tpl->parseChunk($this->lang['payments.payment_description'], [
                'order_id'  => $order['id'],
                'site_name' => $this->modx->getConfig('site_name'),
            ]),
            'client'      => $order['name'],
            'email'       => $order['email'],
            'country'     => $order['fields']['country'] ?: 'PL',
            'language'    => $order['fields']['lang'] ?: 'en',
            'urlReturn'   => $this->modx->getConfig('site_url') . 'commerce/przelewy/payment-process?' . http_build_query(['paymentHash' => $payment['hash']]),
            'urlStatus'   => $this->modx->getConfig('site_url') . 'commerce/przelewy/payment-process?' . http_build_query(['paymentHash' => $payment['hash']]),
        ];

        foreach (['address', 'zip', 'city', 'phone'] as $addon) {
            if (!empty($order['fields'][$addon])) {
                $data[$addon] = $order['fields'][$addon];
            }
        }

        $items = $this->prepareItems($processor->getCart());

        $isPartialPayment = $payment['amount'] < $order['amount'];

        if ($isPartialPayment) {
            $items = $this->decreaseItemsAmount($items, $order['amount'], $payment['amount']);
        }

        $products = [];

        foreach ($items as $i => $item) {
            $products[] = [
                'sellerId'       => (int)$this->getSetting('posId'),
                'sellerCategory' => 'default',
                'name'           => mb_substr($item['name'], 0, 127),
                'description'    => mb_substr($item['name'], 0, 127),
                'quantity'       => (int)$item['count'],
                'price'          => (int)($item['price'] * 100),
                'number'         => (string)($i + 1)
            ];
        }

        $data['cart'] = $products;
        $data['sign'] = $this->getSign([
            'sessionId'  => $data['sessionId'],
            'merchantId' => (int) $data['merchantId'],
            'amount'     => $data['amount'],
            'currency'   => $data['currency'],
            'crc'        => $this->getSetting('crcId')
        ]);
        $response = $this->request('transaction/register', $data);
        if (isset($response['data']['token'])) {
            $url = $this->getSetting('sandbox') ? 'https://sandbox.przelewy24.pl/' : 'https://secure.przelewy24.pl/';
            return $url . 'trnRequest/' . $response['data']['token'];
        }

        return false;
    }

    public function handleCallback()
    {
        if (!isset($_GET['paymentHash']) || !is_string($_GET['paymentHash']) || !preg_match('/^[a-z0-9]+$/',
                $_GET['paymentHash'])) {
            return false;
        }
        $data = file_get_contents('php://input');
        if ($this->getSetting('debug')) {
            $this->modx->logEvent(0, 1, htmlentities(print_r($data, true)), 'Commerce Przelewy24 Payment Callback Start');
        }
        if(empty($data)) {
            $processor = $this->modx->commerce->loadProcessor();

            try {
                $payment = $processor->loadPaymentByHash($_GET['paymentHash']);

                if (!$payment) {
                    throw new Exception('Payment "' . htmlentities(print_r($_GET['paymentHash'],
                            true)) . '" . not found!');
                }

                if ($payment['paid'] == '1') {
                    $this->modx->sendRedirect(MODX_BASE_URL . 'commerce/przelewy/payment-success?paymentHash=' . $_GET['paymentHash']);
                } else {
                    $this->modx->sendRedirect(MODX_BASE_URL . 'commerce/przelewy/payment-failed?paymentHash=' . $_GET['paymentHash']);
                }
            } catch (Exception $e) {
                $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce Przelewy24 Payment');

                return false;
            }
        } else  {
            $data = json_decode($data, true) ?? [];
            foreach (['merchantId', 'posId', 'sessionId', 'amount', 'originAmount', 'currency', 'orderId', 'methodId', 'statement', 'sign'] as $field) {
                if (!isset($data[$field])) {
                    return false;
                }
            }
        }
        $_data = $data;
        unset($_data['sign']);
        $_data['crc'] = $this->getSetting('crcId');
        $sign = $this->getSign($_data);
        if ($data['sign'] === $sign) {
            $amount = number_format($data['amount'] / 100, 2);
            $data = [
                'merchantId' => $data['merchantId'],
                'posId' => $data['posId'],
                'sessionId' => $data['sessionId'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'orderId' => $data['orderId']
            ];
            $data['sign'] = $this->getSign([
                'sessionId' => $data['sessionId'],
                'orderId' => $data['orderId'],
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'crc' => $this->getSetting('crcId')
            ]);
            $response = $this->request('transaction/verify', $data);
            if (isset($response['data']['status']) && $response['data']['status'] === 'success') {
                $processor = $this->modx->commerce->loadProcessor();

                try {
                    $payment = $processor->loadPaymentByHash($_GET['paymentHash']);

                    if (!$payment) {
                        throw new Exception('Payment "' . htmlentities(print_r($_GET['paymentHash'],
                                true)) . '" . not found!');
                    }

                    $processor->processPayment($payment, $amount);
                } catch (Exception $e) {
                    $this->modx->logEvent(0, 3, 'Payment process failed: ' . $e->getMessage(), 'Commerce Przelewy24 Payment');

                    return false;
                }

                $this->modx->sendRedirect(MODX_BASE_URL . 'commerce/przelewy/payment-success?paymentHash=' . $_GET['paymentHash']);
            }
        } else {
            return false;
        }
    }

    /**
     * @param  string  $method
     * @param  array  $data
     * @return array
     */
    protected function request(string $method, array $data = []): array
    {
        $url = $this->getSetting('sandbox') ? 'https://sandbox.przelewy24.pl/' : 'https://secure.przelewy24.pl/';
        if (is_array($data) && !empty($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        $headers = [
            'Content-Type: application/json',
        ];
        $options = [
            CURLOPT_USERPWD        => $this->getSetting('posId') . ':' . $this->getSetting('secretId'),
            CURLOPT_URL            => $url . 'api/v1/' . $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($method === 'transaction/verify') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        }
        if ($method === 'transaction/register' || $method === 'transaction/verify') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $data;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if ($this->getSetting('debug')) {
            $this->modx->logEvent(0, 1, "URL: <pre>$url</pre>\n\nHeaders: <pre>" . htmlentities(print_r($headers,
                    true)) . "</pre>\n\nRequest data: <pre>" . htmlentities(print_r($data,
                    true)) . "</pre>\n\nResponse data: <pre>" . htmlentities(print_r($response,
                    true)) . "</pre>" . (curl_errno($ch) ? "\n\nError: <pre>" . htmlentities(curl_error($ch)) . "</pre>" : ''),
                'Commerce Przelewy24 Payment Debug: request');
        }

        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * @param  array  $data
     * @return string
     */
    protected function getSign(array $data): string
    {
        $string = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sign = hash('sha384', $string);

        return $sign;
    }
}