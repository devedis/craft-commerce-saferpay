<?php

namespace craft\commerce\saferpay\services;

use Craft;
use craft\helpers\UrlHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Throwable;

class SaferpayService
{
    private Client $client;
    private string $specVersion = '1.41';
    private string $apiUsername;
    private string $apiPassword;
    private string $customerId;
    private string $terminalId;
    private bool $useTestEnvironment;
    private bool $isStandalone;

    public function __construct(
        string $apiUsername,
        string $apiPassword,
        string $customerId,
        string $terminalId,
        bool   $useTestEnvironment,
        bool   $isStandalone,
    )
    {
        $this->apiUsername = $apiUsername;
        $this->apiPassword = $apiPassword;
        $this->customerId = $customerId;
        $this->terminalId = $terminalId;
        $this->useTestEnvironment = $useTestEnvironment;
        $this->isStandalone = $isStandalone;

        $this->client = new Client([
            'base_uri' => $this->useTestEnvironment ? 'https://test.saferpay.com/api/' : 'https://www.saferpay.com/api/',
            'auth' => [$this->apiUsername, $this->apiPassword],

        ]);
    }

    /**
     * @param $transaction
     * @return array
     * @throws ApiException
     */
    public function paymentPageInitialize($transaction, $webhookUrl): array
    {
        $order = $transaction->getOrder();
        $billingAddress = $order->getBillingAddress();

        $descTitle = Craft::$app->getSites()->currentSite->name;

        // TODO - This is a temporary solution to get the title of the product. This should be improved.
        if (isset($order->lineItems[0]->snapshot)) {
            $snapshot = $order->lineItems[0]->snapshot;
            $descTitle = $snapshot['title'];
        }

        $billingAddressArray = [
            'FirstName' => $billingAddress['firstName'] ?? null,
            'LastName' => $billingAddress['lastName'] ?? null,
            'Street' => $billingAddress['addressLine1'] ?? null,
            'Street2' => $billingAddress['addressLine2'] ?? null,
            'Zip' => $billingAddress['postalCode'] ?? null,
            'City' => $billingAddress['locality'] ?? null,
            'CountrySubdivisionCode' => $billingAddress['countryCode'] ?? null,
            'Email' => $billingAddress->owner->email ?? null,
        ];

        if ($billingAddress['organization']) {
            $billingAddressArray['Company'] = $billingAddress['organization'];
        }

        $localeCode = $billingAddress['countryCode'] ?? Craft::$app->getSites()->currentSite->getLocale()->getLanguageID();

        $amount = $transaction['paymentAmount'] * 100;

        $fields = [
            'RequestHeader' => [
                'SpecVersion' => $this->specVersion,
                'CustomerId' => $this->customerId,
                'RequestId' => $transaction['hash'],
                // RetryIndicator Should be unique for each new request. If a request is retried due to an error, use the same request id.
                // TODO In this case, the RetryIndicator should be increased instead, to indicate a subsequent attempt.
                'RetryIndicator' => 0,
            ],
            'TerminalId' => $this->terminalId,
            'Payment' => [
                'Amount' => [
                    'Value' => "$amount",
                    'CurrencyCode' => $transaction['paymentCurrency']
                ],
                'OrderId' => $transaction['orderId'],
                'Description' => $descTitle
            ],
            'Payer' => [
                'LanguageCode' => strtoupper($localeCode),
                'BillingAddress' => $billingAddressArray
            ],
            'ReturnUrl' => [
                'Url' => $this->isStandalone
                    ? UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash])
                    : $transaction->order->returnUrl,
            ],
            'Notification' => [
                'FailNotifyUrl' => $webhookUrl,
                'SuccessNotifyUrl' => $webhookUrl,
            ]
        ];

        Craft::info(json_encode(['POST' => $_POST, 'Posted info to SaferPay' => $fields, 'Type' => 'Default Pay']), 'commerce-saferpay');

        try {
            $response = $this->client->post('Payment/v1/PaymentPage/Initialize', [
                'json' => $fields,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
            throw new ApiException($e->getMessage(), $e->getCode(), $response);
        } catch (Throwable $e) {
            throw new ApiException($e->getMessage(), $e->getCode(), []);
        }
    }

    /**
     * @param $token
     * @return mixed
     * @throws ApiException
     */
    public function paymentPageAssert($token): array
    {
        $requestData = [
            'RequestHeader' => [
                ...$this->getCommonRequestHeader(),
                'RequestId' => uniqid(),
                'RetryIndicator' => 0
            ],
            'Token' => $token,
        ];

        try {
            $response = $this->client->post('Payment/v1/PaymentPage/Assert', [
                'json' => $requestData,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
            throw new ApiException($e->getMessage(), $e->getCode(), $response);
        } catch (Throwable $e) {
            throw new ApiException($e->getMessage(), $e->getCode(), []);
        }
    }

    /**
     * @param $transaction
     * @param $transactionId
     * @return mixed
     * @throws ApiException
     */
    public function transactionCapture($transaction, $transactionId): array
    {
        $requestData = [
            "RequestHeader" => [
                ...$this->getCommonRequestHeader(),
                "RequestId" => $transaction['hash'],
                "RetryIndicator" => 0,
            ],
            "TransactionReference" => [
                "TransactionId" => $transactionId
            ]
        ];

        try {
            $response = $this->client->post('Payment/v1/Transaction/Capture', [
                'json' => $requestData,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
            throw new ApiException($e->getMessage(), $e->getCode(), $response);
        } catch (Throwable $e) {
            throw new ApiException($e->getMessage(), $e->getCode(), []);
        }
    }

    /**
     * @param $transaction
     * @param $transactionId
     * @return mixed
     * @throws ApiException
     */
    public function transactionCancel($transaction, $transactionId): array
    {
        $requestData = [
            "RequestHeader" => [
                ...$this->getCommonRequestHeader(),
                "RequestId" => $transaction['hash'],
                "RetryIndicator" => 0
            ],
            "TransactionReference" => [
                "TransactionId" => $transactionId
            ]
        ];

        try {
            $response = $this->client->post('Payment/v1/Transaction/Cancel', [
                'json' => $requestData,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
            throw new ApiException($e->getMessage(), $e->getCode(), $response);
        } catch (Throwable $e) {
            throw new ApiException($e->getMessage(), $e->getCode(), []);
        }
    }

    private function getCommonRequestHeader(): array
    {
        return [
            "SpecVersion" => $this->specVersion,
            "CustomerId" => $this->customerId,
        ];
    }
}
