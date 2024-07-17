<?php

namespace craft\commerce\saferpay\responses;

use craft\commerce\base\RequestResponseInterface;
use craft\commerce\saferpay\Plugin;

class CheckoutResponse implements RequestResponseInterface
{
    private mixed $data;
    private string $status;

    public function __construct($data, $status = 'redirect')
    {
        $this->status = $status;
        $this->data = $data;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSuccessful(): bool
    {
        return $this->getStatus() === 'successful';
    }

    public function isProcessing(): bool
    {
        return $this->getStatus() === 'processing';
    }

    public function isRedirect(): bool
    {
        return $this->getStatus() === 'redirect';
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectData(): array
    {
        return [];
    }

    public function getRedirectUrl(): string
    {
        $data = Plugin::getInstance()->saferpayService->paymentPageInitialize($this->data);

        if ($data['status'] === 200) {
            $body = $data['body'];
            $redirectUrl = $body['RedirectUrl'];
            $_SESSION["Token"] = $body['Token'];
            $_SESSION["RedirectUrl"] = $body['Expiration'];
            return $redirectUrl;
        }
    }

    public function getTransactionReference(): string
    {
        return $this->data['hash'];
    }

    public function getCode(): string
    {
        return 'code';
    }

    public function getData(): mixed
    {
        return [];
    }

    public function getMessage(): string
    {
        return $this->getStatus();
    }

    public function redirect(): void
    {
        return;
    }
}
