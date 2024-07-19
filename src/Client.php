<?php

namespace Eg\AsyncHttp;

use Eg\AsyncHttp\Buffer\BufferInterface;
use Eg\AsyncHttp\Buffer\FileBuffer;
use Eg\AsyncHttp\Buffer\MemoryBuffer;
use Eg\AsyncHttp\Exception\ResponseException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;

class Client
{
	/**
	 * @var resource
	 */
	private Socket|null $socket = null;

    private string|null $ip;

    private BufferInterface $buffer;

    /**
     * @param string|null $ip IP address from which the connection will be made
     */
    public function __construct(
        string $ip = null,
        BufferInterface $buffer = null
    ){
        $this->ip = $ip;
        $this->buffer = $buffer ?? new MemoryBuffer();
    }

    public function __destruct()
	{
		$this->socket = null;
	}

    public function getIP():string|null
    {
        return $this->ip;
    }

	/**
	 * @param RequestInterface $request
	 * @return void
	 * @throws \Throwable
	 */
	public function send(
		RequestInterface $request
	):ResponseInterface
	{

		if($this->socket == null){
			$this->socket = new Socket(
				$request->getUri(),
                $this->buffer,
                $this->ip);
		}

		$this->socket->send(
			$this->getRequestPayload($request));

		[$version, $code, $status] = $this->getStatusLine(
			$this->socket);

		$header = $this->getHeader(
			$this->socket);

		$body = $this->getBody(
			$this->socket,
			$header);

		if(strcasecmp($header->getConnection(), 'Close') === 0){
			$this->socket = null;
		}

		return new Response(
			$code,
			$header->getArray(),
			$body,
			$version,
			$status);
	}

	private function getStatusLine(
		Socket $socket
	):array
	{
		$start_line = $socket->readLine();

		if(preg_match('/^HTTP\/(\d\.\d)\s+(\d+)\s+([A-Za-z\s]+)$/', $start_line, $matches) == false){
			throw ResponseException::startLine(
				$start_line);
		}

		return array_slice($matches,1);
	}

	private function getHeader(
		Socket $socket
	): Header
	{
		$headers = [];

		do{
			$line = $socket->readLine();

			if(empty($line)){
				break;
			}

			[$key, $value] = explode(':', $line,2);

			$headers[$key] = trim($value);
		}while(true);

		return new Header($headers);
	}

    private function getSingleBodyEncodedBySize(
        Socket $socket,
        int $size
    ):string
    {
        return $socket->readSpecificSize($size);
    }

	private function getSingleBodyEncodedByChunks(
		Socket $socket
	):string
	{
		$body = [];

		do{
			$line = $socket->readLine();

			if(empty($line)){
				break;
			}

			$size = hexdec($line);

			//TODO: Do we need max size ?
			if($size < 1 || $size > 12345678){
				/**
				 * Read 2 \r\n
				 */
				$socket->readLine();
				break;
			}

			/**
			 * Add 2 \r\n
			 */
            $message = $socket->readSpecificSize($size + 2);
			$body[] = substr($message, 0, -2);
		}while(true);

		return implode('', $body);
	}

	private function getBody(
		Socket $socket,
		Header $header
	):string|null
	{
		$length = $header->getContentLength();

		if($length !== null){
			return $this->getSingleBodyEncodedBySize(
                $socket,
                $length);
		}

		if(strcasecmp($header->getTransferEncoding(), 'Chunked') == 0){
			return $this->getSingleBodyEncodedByChunks(
				$socket);
		}

		if(strcasecmp($header->getConnection(), 'Close') === 0){
			return $socket->readToEnd();
		}

		return null;
	}

	private function getRequestPayload(
		RequestInterface $request
	):string
	{
		$uri = $request->getUri();

		$body = strval($request->getBody());
		$path = [
            empty($uri->getPath())
                ? '/'
                : $uri->getPath()
        ];

		if(empty($uri->getQuery()) === false){
			$path[] = $uri->getQuery();
		}

		$payload = [
			sprintf('%s %s HTTP/1.1',
				$request->getMethod(),
				implode('?', $path)),
		];

		foreach ($request->getHeaders() as $name => $value){
			$payload[] = is_array($value)
				? sprintf('%s: %s',$name, implode("\t", $value))
				: sprintf('%s: %s',$name, $value);
		}

		if(in_array($request->getMethod(), ['POST', 'PUT']) && empty($body) === false){
			$payload[] = sprintf('Content-Length: %d', strlen($body));
		}

		$payload[] = null;
		$payload[] = $body;

		return implode("\r\n", $payload);
	}

}