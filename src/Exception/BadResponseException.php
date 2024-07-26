<?php

namespace Eg\AsyncHttp\Exception;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class BadResponseException extends TransferException
{
    private ResponseInterface $response;

    /**
     * @param ResponseInterface $response
     */
    public function __construct(
        ResponseInterface $response
    ){
        $this->response = $response;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    public function getBody(): StreamInterface
    {
        return $this->response->getBody();
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}