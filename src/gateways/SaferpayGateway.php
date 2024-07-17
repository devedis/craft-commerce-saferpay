<?php

namespace craft\commerce\saferpay\gateways;

use Craft;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\saferpay\responses\CheckoutRedirectResponse;
use craft\commerce\saferpay\responses\CheckoutResponse;
use craft\commerce\saferpay\services\ApiException;
use craft\commerce\saferpay\services\SaferpayService;
use craft\helpers\App;
use craft\web\Response as WebResponse;

class SaferpayGateway extends BaseGateway
{
    /**
     * @var string (standalone|headless)
     */
    public string $integration = 'standalone';

    /**
     * only used for headless mode
     */
    private ?string $_returnUrl = null;

    private bool|string|null $_useTestEnvironment = null;

    private ?string $_apiUsername = null;

    private ?string $_apiPassword = null;

    private ?string $_customerId = null;

    private ?string $_terminalId = null;

    private ?SaferpayService $_saferpayService = null;

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
            $data = $this->getSaferpayService()->paymentPageInitialize($transaction);
            return new CheckoutRedirectResponse(200, $data);
        } catch (ApiException $e) {
            return new CheckoutResponse(null, $e->getCode(), $e->getResponseBody(), 'error');
        }
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $token = $transaction->reference;

        if (!$token) {
            return new CheckoutResponse(null, null, "No token set", 'error');
        }

        try {
            $response = $this->getSaferpayService()->paymentPageAssert($token);
            $transactionStatus = $response['Transaction']['Status']; //  'AUTHORIZED', 'CANCELED', 'CAPTURED' or 'PENDING'

            if ($transactionStatus === 'AUTHORIZED') {
                $captureResponse = $this->getSaferpayService()->transactionCapture($transaction, $response['Transaction']['Id']);
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
//            $cancelResponse = $this->>getSaferpayService()->transactionCancel($transaction, $response['TransactionId']);
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

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-saferpay/gatewaySettings/gatewaySettings', ['gateway' => $this]);
    }

    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $settings['integration'] = $this->integration;
        $settings['returnUrl'] = $this->getReturnUrl(false);
        $settings['useTestEnvironment'] = $this->getUseTestEnvironment(false);
        $settings['apiUsername'] = $this->getApiUsername(false);
        $settings['apiPassword'] = $this->getApiPassword(false);
        $settings['customerId'] = $this->getCustomerId(false);
        $settings['terminalId'] = $this->getTerminalId(false);

        return $settings;
    }

    public function getReturnUrl(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_returnUrl) : $this->_returnUrl;
    }

    public function getUseTestEnvironment(bool $parse = true): bool|string|null
    {
        return $parse ? App::parseEnv($this->_useTestEnvironment) : $this->_useTestEnvironment;
    }

    public function getApiUsername(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_apiUsername) : $this->_apiUsername;
    }

    public function getApiPassword(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_apiPassword) : $this->_apiPassword;
    }

    public function getCustomerId(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_customerId) : $this->_customerId;
    }

    public function getTerminalId(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_terminalId) : $this->_terminalId;
    }

    public function setReturnUrl(?string $returnUrl): void
    {
        $this->_returnUrl = $returnUrl;
    }

    public function setUseTestEnvironment(string|bool|null $useTestEnvironment): void
    {
        $this->_useTestEnvironment = $useTestEnvironment;
    }

    public function setApiUsername(?string $apiUsername): void
    {
        $this->_apiUsername = $apiUsername;
    }

    public function setApiPassword(?string $apiPassword): void
    {
        $this->_apiPassword = $apiPassword;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->_customerId = $customerId;
    }

    public function setTerminalId(?string $terminalId): void
    {
        $this->_terminalId = $terminalId;
    }

    public function getSaferpayService(): SaferpayService
    {
        if ($this->_saferpayService == null) {
            $this->_saferpayService = new SaferpayService(
                $this->getApiUsername(),
                $this->getApiPassword(),
                $this->getCustomerId(),
                $this->getTerminalId(),
                $this->getUseTestEnvironment(),
                $this->integration === 'standalone',
                $this->getReturnUrl()
            );
        }

        return $this->_saferpayService;
    }
}
