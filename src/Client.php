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

    const CONTENT_LENGTH = 'Content-Length';
    const TRANSFER_ENCODING = 'Transfer-Encoding';
    const CONNECTION = 'Connection';
	/**
	 * @var resource
	 */
	private Socket|null $socket = null;

    private string|null $ip;

    private BufferInterface $buffer;
    private State $state = State::CONNECTING;



    private RequestInterface $request;
    private string $version;
    private int $status;
    private string $code;

    private array $headers = [];
    private array $body = [];

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
	):void
	{

        if(($this->state === State::CONNECTING || $this->state === State::DONE) === false){
            return;
        }

        $this->request = $request;

		if($this->socket == null || $this->ip != $ip){
            $this->ip = $ip;
			$this->socket = new Socket(
				$request->getUri(),
                $this->buffer,
                $this->ip);

            $this->state = State::WAIT_FOR_WRITE;
            return;
		}

        $this->state = State::READY_TO_WRITING;
	}

    public function tick():void
    {
        switch ($this->state) {
            case State::WAIT_FOR_WRITE:
                if($this->socket->isReadyToWrite()){
                    $this->state = State::READY_TO_WRITING;
                }
            break;
            case State::READY_TO_WRITING:
                $this->socket->send($this->getRequestPayload());
                $this->state = State::WAIT_FOR_READ;
            break;
            case State::WAIT_FOR_READ:
                if($this->socket->isReadyToRead()){
                    $this->state = State::READING_STATUS_LINE;
                }
            break;
            case State::READING_STATUS_LINE:
                $status_line = $this->getStatusLine();
                if($status_line === false){
                    return;
                }

                [$this->version, $this->status, $this->code] = $status_line;
                $this->state = State::READING_HEADERS;
            break;
            case State::READING_HEADERS:
                $line = $this->socket->readLine();
                if($line === false){
                    return;
                }

                if(empty($line)){
                    $this->state = State::READING_BODY;
                }

                [$key, $value] = explode(':', $line,2);

                $this->headers[trim($key)] = trim($value);
            break;
            case State::READING_BODY:
                if(($this->headers[self::CONTENT_LENGTH] ?? null) !== null){
                    $this->state = State::READING_BODY_ENCODED_BY_SIZE;
                }else if(strcasecmp($this->headers[self::TRANSFER_ENCODING] ?? '', 'Chunked') == 0){
                    $this->state = State::READING_BODY_CHUNKED_SIZE;
                }else if(strcasecmp($this->headers[self::CONNECTION] ?? '', 'Close') === 0){
                    $this->state = State::READING_BODY_TO_END;
                }else{
                    $this->state = State::DONE;
                }
            break;
            case State::READING_BODY_ENCODED_BY_SIZE:
                $size = $this->headers[self::CONTENT_LENGTH];
                $body = $this->socket->readSpecificSize(
                    $size);

                if($body === false){
                    return;
                }

                $this->body[] = $body;
                $this->state = State::DONE;
            break;
            case State::READING_BODY_CHUNKED_SIZE:
                $line = $this->socket->readLine();

                if($line === false){
                    return;
                }

                if(strlen($line) === 0){
                    $this->state = State::DONE;
                    return;
                }

                $size = hexdec($line);
                $this->state = State::READING_BODY_CHUNKED_BODY;
            break;
            case State::READING_BODY_CHUNKED_BODY:

                $body = $this->socket->readSpecificSize(
                    $size);

                if($body === false){
                    return;
                }

                $this->body[] = $body;
                $this->state = State::READING_BODY_CHUNKED_SIZE;
            break;
            case State::READING_BODY_TO_END:

            break;
        }
    }


    public function reset():void{
        $this->pool = null;
        $this->socket = null;

        $this->buffer->reset();
        //$this->state = State::READY;
    }

	private function getStatusLine():array|false
	{
        $line = $this->socket->readLine(512);
        if($line === false){
            return false;
        }

		if(preg_match('/^HTTP\/(\d\.\d)\s+(\d+)\s+([A-Za-z\s]+)$/', $line, $matches) == false){
			throw ResponseException::startLine(
				$line);
		}

		return array_slice($matches,1);
	}

	private function getRequestPayload():string
	{
		$uri = $this->request->getUri();

		$body = strval($this->request->getBody());
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
                $this->request->getMethod(),
				implode('?', $path)),
		];

		foreach ($this->request->getHeaders() as $name => $value){
			$payload[] = is_array($value)
				? sprintf('%s: %s',$name, implode("\t", $value))
				: sprintf('%s: %s',$name, $value);
		}

		if(in_array($this->request->getMethod(), ['POST', 'PUT']) && empty($body) === false){
			$payload[] = sprintf('Content-Length: %d', strlen($body));
		}

		$payload[] = null;
		$payload[] = $body;

		return implode("\r\n", $payload);
	}

}