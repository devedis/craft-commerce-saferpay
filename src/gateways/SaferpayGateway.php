<?php

namespace craft\commerce\saferpay\gateways;

use Craft;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\errors\TransactionException;
use craft\commerce\events\TransactionEvent;
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
use craft\commerce\services\Payments;
use craft\helpers\App;
use craft\web\Response as WebResponse;
use Exception;

class SaferpayGateway extends BaseGateway
{
    /**
     * @var string (standalone|headless)
     */
    public string $integration = 'standalone';

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
            'commerceTransactionHash' => $transaction->hash,
        ]);

        try {
            $data = $this->getSaferpayService()->paymentPageInitialize($transaction, $webhookUrl);
            return new CheckoutRedirectResponse(200, $data);
        } catch (ApiException $e) {
            Craft::error($e->getMessage(), 'commerce-saferpay');
            return new CheckoutResponse(null, $e->getCode(), $e->getResponseBody(), TransactionRecord::STATUS_FAILED);
        }
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction, ?bool $isWebhook = false): RequestResponseInterface
    {
        // TODO if canceled on payment page, and coming back, the webhook might already created a failed child transaction, so it creates two failed transactions
        try {
            $assert = $this->getSaferpayService()->paymentPageAssert($transaction->reference);
            $transactionStatus = $assert['Transaction']['Status']; //  'AUTHORIZED', 'CANCELED', 'CAPTURED' or 'PENDING'

            $reference = $assert['Transaction']['Id'];

            if ($transactionStatus === 'AUTHORIZED') {
                try {
                    $captureResponse = $this->getSaferpayService()->transactionCapture($transaction, $assert['Transaction']['Id']);

                    $data = [
                        'ASSERT_RESPONSE' => $assert,
                        'CAPTURE_RESPONSE' => $captureResponse,
                    ];

                    return new CheckoutResponse($reference, 200, $data, TransactionRecord::STATUS_SUCCESS);
                } catch (ApiException $e) {
                    Craft::error($e->getMessage(), 'commerce-saferpay');

                    $data = [
                        'ASSERT_RESPONSE' => $assert,
                        'CAPTURE_RESPONSE' => $e->getResponseBody(),
                    ];

                    return new CheckoutResponse($reference, $e->getCode(), $data, TransactionRecord::STATUS_FAILED);
                }
            } else if ($transactionStatus === 'CANCELED') {
                return new CheckoutResponse($reference, 200, $assert, TransactionRecord::STATUS_FAILED);
            } else if ($transactionStatus === 'CAPTURED') {
                return new CheckoutResponse($reference, 200, $assert, TransactionRecord::STATUS_SUCCESS);
            } else if ($transactionStatus === 'PENDING') {
                return new CheckoutResponse($reference, 200, $assert, TransactionRecord::STATUS_PROCESSING);
            } else {
                // TODO
                return new CheckoutResponse($reference, 200, $assert, TransactionRecord::STATUS_FAILED);
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

            return new CheckoutResponse($errorBody['TransactionId'], $e->getCode(), $data, TransactionRecord::STATUS_FAILED);
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
        Craft::info('Processing webhook', 'craft-commerce-saferpay');

        $response = Craft::$app->getResponse();

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            Craft::warning('Transaction with the hash “' . $transactionHash . '“ not found.', 'commerce');

            $response->data = 'ok';
            return $response;
        }

        $errorMessage = '';
        $success = $this->completePayment($transaction, $errorMessage);

        if (!$success) {
            Craft::warning("Payment error $errorMessage", 'craft-commerce-saferpay');
        }

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
        return false;
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
        $settings['useTestEnvironment'] = $this->getUseTestEnvironment(false);
        $settings['apiUsername'] = $this->getApiUsername(false);
        $settings['apiPassword'] = $this->getApiPassword(false);
        $settings['customerId'] = $this->getCustomerId(false);
        $settings['terminalId'] = $this->getTerminalId(false);

        return $settings;
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
            );
        }

        return $this->_saferpayService;
    }

    public function getTransactionHashFromWebhook(): ?string
    {
        return Craft::$app->getRequest()->getParam('commerceTransactionHash');
    }

    // Similar to vendor/craftcms/commerce/src/services/Payments.php::completePayment()
    private function completePayment(Transaction $transaction, ?string &$errorMessage): bool {
        $transactionHash = $transaction->hash;

        $transactionLockName = 'saferpayCommerceTransaction:' . $transaction->hash;
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($transactionLockName, 15)) {
            throw new Exception('Unable to acquire a lock for transaction: ' . $transaction->hash);
        }

        $isSuccessful = Commerce::getInstance()->getTransactions()->isTransactionSuccessful($transaction);

        if ($isSuccessful) {
            Craft::warning('Successful child transaction for “' . $transactionHash . '“ already exists.', 'commerce');
            $transaction->order->updateOrderPaidInformation();
            $mutex->release($transactionLockName);
            return true;
        }

        // Ensure complete purchase is called.

        // Load payment driver for the transaction we are trying to complete
        $gateway = $transaction->getGateway();

        switch ($transaction->type) {
            case TransactionRecord::TYPE_PURCHASE:
                $response = $gateway->completePurchase($transaction, true);
                break;
            // No TYPE_AUTHORIZE.
            default:
                $mutex->release($transactionLockName);
                $errorMessage = 'Transaction type not supported.';
                return false;
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $this->_updateTransaction($childTransaction, $response);

        // Success can mean 2 things in this context.
        // 1) The transaction completed successfully with the gateway, and is now marked as complete.
        // 2) The result of the gateway request was successful but also got a redirect response. We now need to redirect if $redirect is not null.
        $success = $response->isSuccessful() || $response->isProcessing();
        $isParentTransactionRedirect = ($transaction->status === TransactionRecord::STATUS_REDIRECT);

        if ($success) {
            if ($transaction->status === TransactionRecord::STATUS_SUCCESS || ($isParentTransactionRedirect && $childTransaction->status == TransactionRecord::STATUS_SUCCESS)) {
                $transaction->order->updateOrderPaidInformation();
            }

            if ($isParentTransactionRedirect && $childTransaction->status == TransactionRecord::STATUS_PROCESSING) {
                $transaction->order->markAsComplete();
            }
        }

        if ($this->hasEventHandlers(Payments::EVENT_AFTER_COMPLETE_PAYMENT)) {
            $this->trigger(Payments::EVENT_AFTER_COMPLETE_PAYMENT, new TransactionEvent([
                'transaction' => $transaction,
            ]));
        }

        $mutex->release($transactionLockName);

        if (!$success) {
            $errorMessage = $response->getMessage();
        }

        return $success;
    }

    private function _updateTransaction(Transaction $transaction, RequestResponseInterface $response): void
    {
        if ($response->isSuccessful()) {
            $transaction->status = TransactionRecord::STATUS_SUCCESS;
        } elseif ($response->isProcessing()) {
            $transaction->status = TransactionRecord::STATUS_PROCESSING;
        } elseif ($response->isRedirect()) {
            $transaction->status = TransactionRecord::STATUS_REDIRECT;
        } else {
            $transaction->status = TransactionRecord::STATUS_FAILED;
        }

        $transaction->response = $response->getData();
        $transaction->code = $response->getCode();
        $transaction->reference = $response->getTransactionReference();
        $transaction->message = $response->getMessage();

        if (!Commerce::getInstance()->getTransactions()->saveTransaction($transaction)) {
            throw new TransactionException('Error saving transaction: ' . implode(', ', $transaction->errors));
        }
    }
}
