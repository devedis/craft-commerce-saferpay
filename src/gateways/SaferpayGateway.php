<?php

namespace craft\commerce\saferpay\gateways;

use Craft;
use craft\commerce\saferpay\Plugin;
use craft\commerce\saferpay\responses\CheckoutRedirectResponse;
use craft\commerce\saferpay\services\ApiException;
use craft\web\Response as WebResponse;
use craft\commerce\models\Transaction;
use craft\commerce\models\PaymentSource;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\saferpay\responses\CheckoutResponse;

class SaferpayGateway extends BaseGateway
{
    public static function displayName(): string
    {
        return 'Saferpay';
    }

    public function getPaymentFormHtml(array $params): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-saferpay/form.twig');
    }

    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        Craft::info('Authorize', 'craft-commerce-saferpay');
        dd("authorize");
        // TODO implement authorize() method
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        Craft::info('Capture', 'craft-commerce-saferpay');
        dd("capture");
        // TODO implement capture() method
    }

    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        Craft::info('CompleteAuthorize', 'craft-commerce-saferpay');
        dd("completeAuthorize");
        // TODO implement completeAuthorize() method
    }

    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        try {
            $data = Plugin::getInstance()->saferpayService->paymentPageInitialize($transaction);
            $_SESSION["Token"] = $data['Token'];

            return new CheckoutRedirectResponse(200, $data);
        } catch (ApiException $e) {
            return new CheckoutResponse(null, $e->getCode(), $e->getResponseBody(), 'error');
        }
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $token = $_SESSION["Token"] ?? null;

        if (!$token) {
            return new CheckoutResponse(null, null, "No token set", 'error');
        }

        try {
            $response = Plugin::getInstance()->saferpayService->paymentPageAssert($token);
            $transactionStatus = $response['Transaction']['Status']; //  'AUTHORIZED', 'CANCELED', 'CAPTURED' or 'PENDING'

            if ($transactionStatus === 'AUTHORIZED') {
                $captureResponse = Plugin::getInstance()->saferpayService->transactionCapture($transaction, $response['Transaction']['Id']);
                $data = [
                    'ASSERT_RESPONSE' => $response,
                    'CAPTURE_RESPONSE' => $captureResponse,
                ];

                return new CheckoutResponse($response['Transaction']['Id'], 200, $data, 'successful');
            } else if ($transactionStatus === 'CANCELED') {
                return new CheckoutResponse($response['Transaction']['Id'], 200, $response, 'error');
            } else if ($transactionStatus === 'CAPTURED') {
                return new CheckoutResponse($response['Transaction']['Id'], 200, $response, 'successful');
            } else if ($transactionStatus === 'PENDING') {
                return new CheckoutResponse($response['Transaction']['Id'], 200, $response, 'processing');
            } else {
                return new CheckoutResponse($response['Transaction']['Id'], 200, $response, 'error');
            }
        } catch (ApiException $e) {
            $response = $e->getResponseBody();

            $aborted = $response['ErrorName'] === 'TRANSACTION_ABORTED';

            // TODO no cancel required
//            $cancelResponse = Plugin::getInstance()->saferpayService->transactionCancel($transaction, $response['TransactionId']);
            $data = [
                'ASSERT_RESPONSE' => $response,
//                'CANCEL_RESPONSE' => $cancelResponse,
            ];

            return new CheckoutResponse($response['TransactionId'], $e->getCode(), $data, $aborted ? 'aborted' : 'error');
        }
    }

    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        Craft::info('CreatePaymentSource', 'craft-commerce-saferpay');
        dd("createPaymentSource");
        // TODO: Implement createPaymentSource() method.
    }

    public function deletePaymentSource(string $token): bool
    {
        Craft::info('DeletePaymentSource', 'craft-commerce-saferpay');
        dd("deletePaymentSource");
        // TODO: Implement deletePaymentSource() method.
    }

    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm();
    }

    public function refund(Transaction $transaction): RequestResponseInterface
    {
        Craft::info('Refund', 'craft-commerce-saferpay');
        dd("refund");
        // TODO: Implement refund() method.
    }

    public function processWebHook(): WebResponse
    {
        Craft::info('ProcessWebHook', 'craft-commerce-saferpay');
        dd("processWebHook");
        // TODO: Implement processWebHook() method.
    }

    public function supportsAuthorize(): bool
    {
        return false;
    }

    public function supportsCapture(): bool
    {
        return false;
    }

    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    public function supportsCompletePurchase(): bool
    {
        return true;
    }

    public function supportsPaymentSources(): bool
    {
        return false;
    }

    public function supportsPurchase(): bool
    {
        return true;
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function supportsPartialRefund(): bool
    {
        return false;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }


}
