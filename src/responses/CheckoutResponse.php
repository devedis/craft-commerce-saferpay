<?php

namespace craft\commerce\saferpay\responses;

use craft\commerce\base\RequestResponseInterface;
use craft\commerce\saferpay\Plugin;

class CheckoutResponse implements RequestResponseInterface
{
    private ?string $transactionId;
    private mixed $data;
    private string $status;
    private ?string $code;

    public function __construct($transactionId, $code, $data, $status = 'redirect')
    {
        $this->transactionId = $transactionId;
        $this->code = $code;
        $this->data = $data;
        $this->status = $status;
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
        return false;
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
        return '';
    }

    public function getTransactionReference(): string
    {
        return $this->transactionId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): string
    {
        return $this->data['ASSERT_RESPONSE']['ErrorName'] ?? $this->data['ASSERT_RESPONSE']['Transaction']['Status'] ?? $this->status;
    }

    public function redirect(): void
    {
        //
    }
}
