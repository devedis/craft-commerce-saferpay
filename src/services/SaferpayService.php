<?php

namespace craft\commerce\saferpay\services;

use Craft;
use craft\helpers\UrlHelper;
use yii\base\Component;

class SaferpayService extends Component
{
    private string $specVersion = '1.41';

    /**
     * @param $transaction
     * @return array
     * @throws \craft\errors\SiteNotFoundException
     * @throws ApiException
     */
    public function paymentPageInitialize($transaction): array
    {
        $order = $transaction->getOrder();
        $billingAddress = $order->getBillingAddress();

        $descTitle = "Flying Teachers";
        if (isset($order->lineItems[0]->snapshot)){
            $snapshot = $order->lineItems[0]->snapshot;
            $descTitle = $snapshot['title'];
        }
        $gender = null;

        /*$gender = match ($billingAddress['user_salutation']) {
            'Mr.' => 'MALE',
            'Mrs.' => 'FEMALE',
            default => null,
        };*/

        /*if (isset($billingAddress['user_birthday'])) {
            $dateOfBirth = $billingAddress['user_birthday'];

            if ($dateOfBirth instanceof DateTime) {
                $formattedDateOfBirth = $dateOfBirth->format('Y-m-d');
            } else {
                $formattedDateOfBirth = null;
            }
        } else {
            $formattedDateOfBirth = null;
        }*/
        $formattedDateOfBirth = null;

        $billingAddressArray = [
//            'FirstName' => $billingAddress['user_firstName'] ?? null,
            'FirstName' => $billingAddress['firstName'] ?? null,
//            'LastName' => $billingAddress['user_lastName'] ?? null,
            'LastName' => $billingAddress['lastName'] ?? null,
            'Gender' => $gender,
            'Street' => $billingAddress['addressLine1'] ?? '',
            'Street2' => $billingAddress['addressLine2'] ? $billingAddress['addressLine2']: 'None',
            'Zip' => $billingAddress['postalCode'] ?? null,
            'City' => $billingAddress['locality'] ?? null,
//            'CountrySubdivisionCode' => $billingAddress['user_country'] ?? null,
            'CountrySubdivisionCode' => $billingAddress['countryCode'] ?? null,
            'Email' => $billingAddress->owner->email ?? null,
//            'Phone' => $billingAddress['user_phone'] ?? null,
        ];
        if  ($formattedDateOfBirth){
            $billingAddressArray['DateOfBirth'] = $formattedDateOfBirth;
        }
        if  ($billingAddress['organization']){
            $billingAddressArray['Company'] = $billingAddress['organization'];
        }
        $localeCode = $billingAddress['countryCode'] ?? Craft::$app->getSites()->currentSite->getLocale()->getLanguageID();
        $fields = [
            'RequestHeader' => [
                'SpecVersion' => $this->specVersion,
                'CustomerId' => getenv('SAFERPAY_CUSTOMER_ID'),
                'RequestId' => $transaction['hash'],
                'RetryIndicator' => 0, // Should be unique for each new request. If a request is retried due to an error, use the same request id. In this case, the RetryIndicator should be increased instead, to indicate a subsequent attempt.
            ],
            'TerminalId' => getenv('SAFERPAY_TERMINAL_ID'),
            //'PaymentMethods' => ["DIRECTDEBIT", "SOFORT", "EPS", "GIROPAY", "VISA", "MASTERCARD"],
            'Payment' => [
                'Amount' => [
                    'Value' => (string)$transaction['paymentAmount'] * 100,
                    'CurrencyCode' => $transaction['currency']
                ],
                'OrderId' => $transaction['orderId'],
                'Description' => $descTitle
            ],
            'Payer' => [
                'LanguageCode' => strtoupper($localeCode),
                'BillingAddress' => $billingAddressArray
            ],
            'ReturnUrl' => [
                'Url' => UrlHelper::actionUrl('commerce/payments/complete-payment', ['commerceTransactionId' => $transaction->id, 'commerceTransactionHash' => $transaction->hash]),
            ],
        ];
        \Craft::info(
            json_encode(['POST' => $_POST, 'Posted info to SaferPay' => $fields, 'Type' => 'Default Pay']), 'commerce-saferpay'
        );
        $url = getenv('SAFERPAY_API_URL') . 'Payment/v1/PaymentPage/Initialize';
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json", "Accept: application/json; charset=utf-8"]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_USERPWD, getenv('SAFERPAY_USERNAME') . ":" . getenv('SAFERPAY_PASSWORD'));

        $response = curl_exec($curl);
        $body = json_decode($response, true);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl) ?? "API Error";
        $errorNo = curl_errno($curl);
        curl_close($curl);

        if ($errorNo || $status !== 200) {
            throw new ApiException($error, $status, $body);
        }

        return $body;
    }

    /**
     * @param $token
     * @return mixed
     * @throws ApiException
     */
    public function paymentPageAssert($token): array
    {
        $apiUrl = getenv('SAFERPAY_API_URL') . 'Payment/v1/PaymentPage/Assert';
        $customerId = getenv('SAFERPAY_CUSTOMER_ID');;

        $requestData = [
            'RequestHeader' => [
                'SpecVersion' => $this->specVersion,
                'CustomerId' => $customerId,
                'RequestId' => uniqid(),
                'RetryIndicator' => 0
            ],
            'Token' => $token,
        ];

        $jsonData = json_encode($requestData);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $apiUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json", "Accept: application/json; charset=utf-8"]);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_USERPWD, getenv('SAFERPAY_USERNAME') . ":" . getenv('SAFERPAY_PASSWORD'));
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);

        $response = curl_exec($curl);
        $body = json_decode($response, true);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errorNo = curl_errno($curl);
        $error = curl_error($curl) ?? "API Error";
        curl_close($curl);

        if ($errorNo || $status !== 200) {
            throw new ApiException($error, $status, $body);
        }

        return $body;
    }

    /**
     * @param $transaction
     * @param $transactionId
     * @return mixed
     */
    public function transactionCapture($transaction, $transactionId)
    {
        $requestData = [
            "RequestHeader" => [
                "SpecVersion" => $this->specVersion,
                "CustomerId" => getenv('SAFERPAY_CUSTOMER_ID'),
                "RequestId" => $transaction['hash'],
                "RetryIndicator" => 0,
            ],
            "TransactionReference" => [
                "TransactionId" => $transactionId
            ]
        ];

        $jsonData = json_encode($requestData);

        $apiUrl = getenv('SAFERPAY_API_URL') . 'Payment/v1/Transaction/Capture';

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $apiUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_USERPWD, getenv('SAFERPAY_USERNAME') . ":" . getenv('SAFERPAY_PASSWORD'));
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json", "Accept: application/json; charset=utf-8"]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            echo "cURL Error: " . curl_error($curl);
        }

        curl_close($curl);

        return json_decode($response, true);
    }

    /**
     * @param $transaction
     * @param $transactionId
     * @return mixed
     */
    public function transactionCancel($transaction, $transactionId)
    {
        $requestData = [
            "RequestHeader" => [
                "SpecVersion" => $this->specVersion,
                "CustomerId" => getenv('SAFERPAY_CUSTOMER_ID'),
                "RequestId" => $transaction['hash'],
                "RetryIndicator" => 0
            ],
            "TransactionReference" => [
                "TransactionId" => $transactionId
            ]
        ];

        $jsonData = json_encode($requestData);

        $apiUrl = getenv('SAFERPAY_API_URL') . 'Payment/v1/Transaction/Cancel';

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $apiUrl);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_USERPWD, getenv('SAFERPAY_USERNAME') . ":" . getenv('SAFERPAY_PASSWORD'));
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json", "Accept: application/json; charset=utf-8"]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            echo "cURL Error: " . curl_error($curl);
        }

        curl_close($curl);

        return json_decode($response, true);
    }
}
