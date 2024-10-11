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

	/**
	 * @param RequestInterface $request
	 * @throws \Throwable
	 */
	public function send(
		RequestInterface $request,
        string|null $ip = null
	):callable
	{

        if($this->state != State::READY || $this->state != State::DONE){
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
                static $status_line_reader = $this->getStatusLineReader();
                if($status_line_reader->valid()){
                    $status_line_reader->next();
                    return [$this->state, null];
                }

                static $header_reader = $this->getHeaderReader();
                if($header_reader->valid()){
                    $header_reader->next();
                    return [$this->state, null];
                }

                static $body_reader = $this->getBodyReader(
                    $header_reader->getReturn());
                if($body_reader->valid()){
                    $body_reader->next();
                    return [$this->state, null];
                }

                $this->state = State::DONE;
                [$version, $code, $status] = $status_line_reader->getReturn();

                $response = new Response(
                    $code,
                    $header_reader->getReturn()->getArray(),
                    $body_reader->getReturn(),
                    $version,
                    $status);

                return match(intval($code / 100 )){
                    4 => throw new ClientException($response),
                    5 => throw new ServerException($response),
                    default => [$this->state, $response]
                };
            }

            return [$this->state, null];
        };
	}

	private function getStatusLineReader():Generator
	{
		 $reader = $this->socket->readLine(512);
         while($reader->valid()){
             $reader->next();
             yield;
         }

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
            while($reader->valid()){
                $reader->next();
                yield;
            }

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
            yield from $this->getSingleBodyEncodedBySize($length);
        }else if(strcasecmp($header->getTransferEncoding(), 'Chunked') == 0){
            yield from $this->getSingleBodyEncodedByChunks();
        }else if(strcasecmp($header->getConnection(), 'Close') === 0){
            yield from $this->socket->readToEnd();
        }else{
            return null;
        }
    }

    private function getSingleBodyEncodedBySize(
        int $size
    ):Generator
    {
        yield from $this->socket->readSpecificSize($size);
    }

	private function getSingleBodyEncodedByChunks():Generator
	{
		$body = [];

		do{

            $reader = $this->socket->readLine();
            while($reader->valid()){
                $reader->next();
                yield;
            }

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
                while($reader->valid()){
                    $reader->next();
                    yield;
                }
				break;
			}

			/**
			 * Add 2 \r\n
			 */
            $reader = $this->socket->readSpecificSize($size + 2);
            while($reader->valid()){
                $reader->next();
                yield;
            }
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