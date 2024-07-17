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
        return new CheckoutResponse($transaction);
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        return new CheckoutResponse($transaction);
    }

    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        return new CheckoutResponse($transaction, 'successful');
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

        if (!$response || !isset($response['Transaction']['Status'])) {
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
        // TODO: Implement createPaymentSource() method.
    }

    public function deletePaymentSource(string $token): bool
    {
        // TODO: Implement deletePaymentSource() method.
    }

    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm();
    }

    public function refund(Transaction $transaction): RequestResponseInterface
    {
        // TODO: Implement refund() method.
    }

    public function processWebHook(): WebResponse
    {
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
