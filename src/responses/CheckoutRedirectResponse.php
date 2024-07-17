<?php

namespace craft\commerce\saferpay\responses;

use craft\commerce\base\RequestResponseInterface;

class CheckoutRedirectResponse implements RequestResponseInterface
{
    private string $code;
    private mixed $response;


    public function __construct($code, $response)
    {
        $this->code = $code;
        $this->response = $response;
    }

    public function isSuccessful(): bool
    {
        return false;
    }

    public function isProcessing(): bool
    {
        return false;
    }

    public function isRedirect(): bool
    {
        return true;
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
        return $this->response['RedirectUrl'];
    }

    public function getTransactionReference(): string
    {
        return $this->response['Token'];
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getData(): mixed
    {
        return $this->response;
    }

    public function getMessage(): string
    {
        return 'Redirect to external Payment Form';
    }

    public function redirect(): void
    {
        //
    }
}
