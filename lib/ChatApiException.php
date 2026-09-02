<?php

/**
 * Provider-neutral exception for chat-model API failures.
 *
 * Target: PHP 7.4.
 */
class ChatApiException extends RuntimeException
{
    /** @var string */
    private $errorType;

    /** @var int|null */
    private $status;

    public function __construct($message, $errorType = 'api_error', $status = null)
    {
        parent::__construct($message);
        $this->errorType = (string) $errorType;
        $this->status    = $status !== null ? (int) $status : null;
    }

    /** @return string */
    public function getErrorType()
    {
        return $this->errorType;
    }

    /** @return int|null */
    public function getStatus()
    {
        return $this->status;
    }

    /** @return bool */
    public function isTransient()
    {
        return in_array($this->errorType, ['rate_limit_error', 'overloaded_error', 'api_error', 'network'], true)
            || ($this->status !== null && $this->status >= 500);
    }
}
