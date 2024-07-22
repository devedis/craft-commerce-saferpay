<?php

namespace craft\commerce\saferpay\gateways;

use Craft;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
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
        return Craft::$app->getView()->renderTemplate('commerce-saferpay/form');
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
        $webhookUrl = $this->getWebhookUrl([
            'commerceTransactionId' => $transaction->id,
            'commerceTransactionHash' => $transaction->hash,
        ]);

        try {
            $data = $this->getSaferpayService()->paymentPageInitialize($transaction, $webhookUrl);
            return new CheckoutRedirectResponse(200, $data);
        } catch (ApiException $e) {
            Craft::error($e->getMessage(), 'commerce-saferpay');
            return new CheckoutResponse(null, $e->getCode(), $e->getResponseBody(), 'error');
        }
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $token = $transaction->reference;

        if (!$token) {
            return new CheckoutResponse(null, null, "No token set", 'error');
        }

        return new CheckoutResponse($token, 200, [], 'processing');
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
        Craft::info('Processing webhook', 'craft-commerce-saferpay');

        $response = Craft::$app->getResponse();

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            Craft::warning('Transaction with the hash “' . $transactionHash . '“ not found.', 'commerce');

            $response->data = 'ok';
            return $response;
        }

        // If the transaction is already successful, we don't need to do anything.
        $successfulPurchaseChildTransaction = TransactionRecord::find()->where([
            'parentId' => $transaction->id,
            'status' => TransactionRecord::STATUS_SUCCESS,
            'type' => TransactionRecord::TYPE_PURCHASE,
        ])->count();

        if ($successfulPurchaseChildTransaction) {
            Craft::warning('Successful child transaction for “' . $transactionHash . '“ already exists.', 'commerce');

            $response->data = 'ok';
            return $response;
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;

        try {
            $assert = $this->getSaferpayService()->paymentPageAssert($transaction->reference);
            $transactionStatus = $assert['Transaction']['Status']; //  'AUTHORIZED', 'CANCELED', 'CAPTURED' or 'PENDING'

            $childTransaction->code = 200;
            $childTransaction->response = $assert;
            $childTransaction->message = 'Webhook';
            $childTransaction->reference = $assert['Transaction']['Id'];

            if ($transactionStatus === 'AUTHORIZED') {
                try {
                    $captureResponse = $this->getSaferpayService()->transactionCapture($transaction, $assert['Transaction']['Id']);

                    $data = [
                        'ASSERT_RESPONSE' => $assert,
                        'CAPTURE_RESPONSE' => $captureResponse,
                    ];
                    $childTransaction->response = $data;

                    $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
                } catch (ApiException $e) {
                    Craft::error($e->getMessage(), 'commerce-saferpay');

                    $data = [
                        'ASSERT_RESPONSE' => $assert,
                        'CAPTURE_RESPONSE' => $e->getResponseBody(),
                    ];

                    $childTransaction->code = $e->getCode();
                    $childTransaction->response = $data;
                    $childTransaction->message = 'Capture failed';
                    $childTransaction->status = TransactionRecord::STATUS_FAILED;
                }
            } else if ($transactionStatus === 'CANCELED') {
                $childTransaction->status = TransactionRecord::STATUS_FAILED;
            } else if ($transactionStatus === 'CAPTURED') {
                $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
            } else if ($transactionStatus === 'PENDING') {
                $childTransaction->status = TransactionRecord::STATUS_PROCESSING;
            } else {
                $childTransaction->status = TransactionRecord::STATUS_FAILED;
            }
        } catch (ApiException $e) {
            Craft::error($e->getMessage(), 'commerce-saferpay');

            $errorBody = $e->getResponseBody();
            $aborted = $errorBody['ErrorName'] === 'TRANSACTION_ABORTED';

            // TODO no cancel required
//            $cancelResponse = $this->>getSaferpayService()->transactionCancel($transaction, $response['TransactionId']);
            $data = [
                'ASSERT_RESPONSE' => $errorBody,
//                'CANCEL_RESPONSE' => $cancelResponse,
            ];

            $childTransaction->code = $e->getCode();
            $childTransaction->response = $data;
            $childTransaction->message = $aborted ? 'aborted' : '';
            $childTransaction->reference = $errorBody['TransactionId'];
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        }

        Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);

        $response->data = 'ok';
        return $response;
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
        return true;
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

    public function getTransactionHashFromWebhook(): ?string
    {
        return Craft::$app->getRequest()->getParam('commerceTransactionHash');
    }
}
