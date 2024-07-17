<?php

namespace craft\commerce\saferpay\services;

use \Exception;

class ApiException extends Exception
{
    /**
     * The HTTP body of the server response either as Json or string.
     *
     * @var mixed
     */
    protected $responseBody;

    /**
     * The deserialized response object
     *
     * @var $responseObject;
     */

    protected $responseObject;
    public function __construct($message, $code = 0, $responseBody = null)
    {
        parent::__construct($message, $code);
        $this->responseBody = $responseBody;
    }

    public function getResponseBody()
    {
        return $this->responseBody;
    }
}
