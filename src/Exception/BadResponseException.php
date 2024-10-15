<?php

namespace Eg\AsyncHttp\Exception;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @method string getProtocolVersion()
 * @method MessageInterface withProtocolVersion(string $version)
 * @method array getHeaders()
 * @method bool hasHeader(string $name)
 * @method array getHeader(string $name)
 * @method string getHeaderLine(string $name)
 * @method MessageInterface withHeader(string $name, $value)
 * @method MessageInterface withAddedHeader(string $name, $value)
 * @method MessageInterface withoutHeader(string $name)
 * @method StreamInterface getBody()
 * @method MessageInterface withBody(StreamInterface $body)
 * @method int getStatusCode()
 * @method ResponseInterface withStatus(int $code, string $reasonPhrase = '')
 * @method string getReasonPhrase()
 */
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

	public function __call($name, $arguments)
	{
		return call_user_func_array([$this->response, $name], $arguments);
	}
}