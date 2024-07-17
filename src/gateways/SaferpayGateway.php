<?php

namespace craft\commerce\saferpay\gateways;

use Craft;
use craft\commerce\saferpay\Plugin;
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
    private array $data = [];

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
        return new CheckoutResponse($transaction);
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $response = null;
        $token = $_SESSION["Token"] ?? null;

        if ($token) {
            $response = Plugin::getInstance()->saferpayService->paymentPageAssert($token);
        }

        if (!$response) {
            return new CheckoutResponse($transaction, 'error');
        }

        if (isset($response['ErrorName']) && $response['ErrorName'] === 'TRANSACTION_ABORTED') {
            Plugin::getInstance()->saferpayService->transactionCancel($transaction, $response['TransactionId']);
            return new CheckoutResponse($transaction, 'aborted');
        }

        if (!isset($response['Transaction']['Status'])) {
            return new CheckoutResponse($transaction, 'error');
        }

        if ($response['Transaction']['Status'] === 'AUTHORIZED') {
            Plugin::getInstance()->saferpayService->transactionCapture($transaction, $response['Transaction']['Id']);
            return new CheckoutResponse($transaction, 'successful');
        } else {
            Plugin::getInstance()->saferpayService->transactionCancel($transaction, $response['Transaction']['Id']);
            return new CheckoutResponse($transaction, 'processing');
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
