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
    private State $state = State::CONNECTING;


    private RequestInterface|null $request;
    private string $version;
    private int|null $status = null;
    private string $code;

    private array $headers = [];
    private array $body = [];
    private int $size;
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

        switch($this->state) {
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
                    return;
                }

                [$key, $value] = explode(':', $line,2);
                $this->headers[trim($key)] = trim($value);
            break;
            case State::READING_BODY:

                $header = new Header($this->headers);
                $size = $header->getContentLength();

                if($size !== null){
                    $this->size = $size;
                    $this->state = State::READING_BODY_ENCODED_BY_SIZE;
                }else if(strcasecmp($header->getTransferEncoding(), 'Chunked') == 0){
                    $this->state = State::READING_BODY_CHUNKED_SIZE;
                }else if(strcasecmp($header->getConnection(), 'Close') === 0){
                    $this->state = State::READING_BODY_TO_END;
                }else{
                    $this->state = State::DONE;
                }
            break;
            case State::READING_BODY_ENCODED_BY_SIZE:
                $body = $this->socket->readSpecificSize(
                    $this->size);

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

                $this->size = hexdec($line);
                $this->state = State::READING_BODY_CHUNKED_BODY;
            break;
            case State::READING_BODY_CHUNKED_BODY:

                $body = $this->socket->readSpecificSize(
                    $this->size);

                if($body === false){
                    return;
                }

                $this->body[] = $body;
                $this->state = State::READING_BODY_CHUNKED_SIZE;
            break;
            case State::READING_BODY_TO_END:
                $body = $this->socket->readToEnd();
                if($body === false){
                    return;
                }
                $this->state = State::DONE;
            break;
        }
    }

    public function isDone():bool
    {
        return $this->state === State::DONE;
    }

	public function getStatus(): int|null
	{
		return $this->status;
	}

    public function getResponse():ResponseInterface|null
    {
		if($this->isDone() === false){
			return null;
		}

        $response = new Response(
            status: $this->status,
            headers: $this->headers,
            body: implode('', $this->body),
            version: $this->version,
            reason: $this->code);

        return match(intval($this->status / 100 )){
            4 => throw new ClientException($response),
            5 => throw new ServerException($response),
            default => $response
        };
    }

    public function reset():void{
        $this->socket = null;
        $this->buffer->reset();
        $this->state = State::CONNECTING;


        $this->request = null;
		$this->version = '';
		$this->status = null;
		$this->code = '';
        $this->headers = [];
        $this->body = [];
        $this->size = 0;
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