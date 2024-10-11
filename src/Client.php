<?php

namespace Eg\AsyncHttp;

use Eg\AsyncHttp\Buffer\BufferInterface;
use Eg\AsyncHttp\Buffer\FileBuffer;
use Eg\AsyncHttp\Buffer\MemoryBuffer;
use Eg\AsyncHttp\Exception\ClientException;
use Eg\AsyncHttp\Exception\ResponseException;
use Eg\AsyncHttp\Exception\ServerException;
use Generator;
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
    private State $state = State::READY;

    /**
     * @var callable
     */
    private $pool;
    /**
     * @param string|null $ip IP address from which the connection will be made
     */
    public function __construct(
        BufferInterface $buffer = null
    ){
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

    private function isActive(): bool
    {
        return !($this->state === State::READY || $this->state === State::DONE);
    }

	/**
	 * @param RequestInterface $request
	 * @throws \Throwable
	 */
	public function send(
		RequestInterface $request,
        string|null $ip = null
	):callable
	{
        if($this->isActive()){
            return $this->pool;
        }

		if($this->socket == null || $this->ip != $ip){
            $this->ip = $ip;
			$this->socket = new Socket(
				$request->getUri(),
                $this->buffer,
                $this->ip);
		}

        $payload = $this->getRequestPayload($request);
        $this->state = State::SENDING;

        return $this->pool = function() use ($payload){
            if($this->state === State::SENDING){
                if($this->socket->send($payload)){
                    $this->state = State::LOADING;
                }
            }

            if($this->state === State::LOADING) {
                static $status_line_reader;

                if(isset($status_line_reader) == false){
                    $status_line_reader = $this->getStatusLineReader();
                }

                if($status_line_reader->valid()){
                    $status_line_reader->next();
                    return [$this->state, null];
                }

                static $header_reader;
                if(isset($header_reader) == false){
                    $header_reader = $this->getHeaderReader();
                }

                if($header_reader->valid()){
                    $header_reader->next();
                    return [$this->state, null];
                }

                static $body_reader;
                if(isset($body_reader) == false){
                    $body_reader = $this->getBodyReader(
                        $header_reader->getReturn());
                }

                if($body_reader->valid()){
                    $body_reader->next();
                    return [$this->state, null];
                }

                $this->state = State::DONE;
                [$version, $status, $code] = $status_line_reader->getReturn();

                $response = new Response(
                    status: $status,
                    headers: $header_reader->getReturn()->getArray(),
                    body: $body_reader->getReturn(),
                    version: $version,
                    reason: $code);

                return match(intval($status / 100 )){
                    4 => throw new ClientException($response),
                    5 => throw new ServerException($response),
                    default => [$this->state, $response]
                };
            }

            return [$this->state, null];
        };
	}

    public function close():void{
        unset($this->pool);
        unset($this->socket);

        $this->buffer->reset();
        $this->state = State::READY;
    }

	private function getStatusLineReader():Generator
	{
		$reader = $this->socket->readLine(512);
        yield from $reader;
        $status_line = $reader->getReturn();

		if(preg_match('/^HTTP\/(\d\.\d)\s+(\d+)\s+([A-Za-z\s]+)$/', $status_line, $matches) == false){
			throw ResponseException::startLine(
				$status_line);
		}

		return array_slice($matches,1);
	}

	private function getHeaderReader():Generator
	{
		$headers = [];

		do{
            $reader = $this->socket->readLine();
            yield from $reader;

            $line = $reader->getReturn();

			if(empty($line)){
				break;
			}

			[$key, $value] = explode(':', $line,2);

			$headers[$key] = trim($value);
		}while(true);

		return new Header($headers);
	}

    private function getBodyReader(
        Header $header
    ):Generator
    {
        $length = $header->getContentLength();

        if($length !== null){
            $reader = $this->getSingleBodyEncodedBySize(
                $length);
        }else if(strcasecmp($header->getTransferEncoding(), 'Chunked') == 0){
            $reader = $this->getSingleBodyEncodedByChunks();
        }else if(strcasecmp($header->getConnection(), 'Close') === 0){
            $reader = $this->socket->readToEnd();
        }else{
            return null;
        }

        yield from $reader;

        return $reader->getReturn();
    }

    private function getSingleBodyEncodedBySize(
        int $size
    ):Generator
    {
        $reader = $this->socket->readSpecificSize($size);
        yield from $reader;
        return $reader->getReturn();
    }

	private function getSingleBodyEncodedByChunks():Generator
	{
		$body = [];

		do{

            $reader = $this->socket->readLine();
            yield from $reader;

            $line = $reader->getReturn();

			if(strlen($line) === 0){
				break;
			}

			$size = hexdec($line);

			//TODO: Do we need max size ?
			if($size < 1 || $size > 12345678){
				/**
				 * Read 2 \r\n
				 */
                $reader = $this->socket->readLine();
                yield from $reader;
				break;
			}

			/**
			 * Add 2 \r\n
			 */
            $reader = $this->socket->readSpecificSize($size + 2);
            yield from $reader;
            $message = $reader->getReturn();
			$body[] = substr($message, 0, -2);
		}while(true);

		return implode('', $body);
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