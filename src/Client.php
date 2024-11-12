<?php

namespace Eg\AsyncHttp;

use Eg\AsyncHttp\Buffer\BufferInterface;
use Eg\AsyncHttp\Buffer\FileBuffer;
use Eg\AsyncHttp\Buffer\MemoryBuffer;
use Eg\AsyncHttp\Exception\ClientException;
use Eg\AsyncHttp\Exception\NetworkException;
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

	/**
	 * @var string|null IP address from which the connection will be made
	 */
	private string|null $local;

	/**
	 * @var string|null IP address to which the connection will be made
	 */
	private string|null $remote;

    private BufferInterface $buffer;
    private State $state = State::DONE;

	private int $timeout;

	private int $connect_timeout;
	private int $read_timeout;

    private RequestInterface|null $request;

    private string $version;
    private int|null $status = null;
    private string $code;

    private array $headers = [];

    private array $body = [];
    private int $size = 0;

    public function __construct(
        BufferInterface $buffer = null,
		string|null $local = null,
		string|null $remote = null,

		int $connect_timeout = 20,
		int $read_timeout = 120,
    ){
        $this->buffer = $buffer ?? new MemoryBuffer();
        $this->local = $local;
        $this->remote = $remote;

		$this->connect_timeout = $connect_timeout;
		$this->read_timeout = $read_timeout;
    }

    public function __destruct()
	{
		$this->socket = null;
	}

	/**
	 * @param RequestInterface $request
	 * @throws \Throwable
	 */
	public function send(
		RequestInterface $request
	):void
	{
        if($this->state !== State::DONE){
            return;
        }

		$this->clear();
        $this->request = $request;

		if(empty($this->socket)){
			$this->socket = new Socket(
				uri: $request->getUri(),
                buffer: $this->buffer,
				local: $this->local,
				remote: $this->remote);

            $this->state = State::WAIT_FOR_WRITE;
			$this->timeout = time() + $this->connect_timeout;
            return;
		}

        $this->state = State::READY_TO_WRITING;
	}

    public function tick():void
    {
		while($this->state !== State::DONE) {

			if ($this->state === State::WAIT_FOR_WRITE) {
				if ($this->socket->isReadyToWrite() !== true) {

					if(time() > $this->timeout){
						throw NetworkException::connectionTimeout();
					}

					return;
				}

				$this->state = State::READY_TO_WRITING;
			}

			if ($this->state === State::READY_TO_WRITING) {

				$this->socket->send($this->getRequestPayload());
				$this->state = State::WAIT_FOR_READ;
				$this->timeout = time() + $this->connect_timeout;
			}


			if ($this->state === State::WAIT_FOR_READ) {
				if ($this->socket->isReadyToRead() !== true) {
					if(time() > $this->timeout){
						throw NetworkException::connectionTimeout();
					}

					return;
				}

				$this->state = State::READING_STATUS_LINE;
				$this->timeout = time() + $this->read_timeout;
			}

			if ($this->state === State::READING_STATUS_LINE) {
				$status_line = $this->getStatusLine();

				if ($status_line === false) {
					if(time() > $this->timeout){
						throw NetworkException::readTimeout();
					}
					return;
				}

				[$this->version, $this->status, $this->code] = $status_line;
				$this->state = State::READING_HEADERS;
				$this->timeout = time() + $this->read_timeout;
			}

			if ($this->state === State::READING_HEADERS) {
				while (true) {
					$line = $this->socket->readLine();
					if ($line === false) {
						if(time() > $this->timeout){
							throw NetworkException::readTimeout();
						}

						return;
					}

					if (empty($line)) {
						$this->state = State::READING_BODY;
						break;
					}

					[$key, $value] = explode(':', $line, 2);
					$this->headers[trim($key)] = trim($value);
					$this->timeout = time() + $this->read_timeout;
				}
			}

			if ($this->state === State::READING_BODY) {
				$header = new Header($this->headers);

				$size = $header->getContentLength();

				if ($size !== null) {
					$this->size = $size;
					$this->state = State::READING_BODY_ENCODED_BY_SIZE;
				} else if (strcasecmp($header->getTransferEncoding(), 'Chunked') == 0) {
					$this->state = State::READING_BODY_CHUNKED_SIZE;
				} else if (strcasecmp($header->getConnection(), 'Close') === 0) {
					$this->state = State::READING_BODY_TO_END;
				} else {
					$this->state = State::DONE;
				}

				$this->timeout = time() + $this->read_timeout;
			}

			if ($this->state === State::READING_BODY_ENCODED_BY_SIZE) {
				$body = $this->socket->readSpecificSize(
					$this->size);

				if ($body === false) {
					if(time() > $this->timeout){
						throw NetworkException::readTimeout();
					}

					return;
				}

				$this->body[] = $body;
				$this->state = State::DONE;
			}

			if ($this->state === State::READING_BODY_CHUNKED_SIZE) {
				$line = $this->socket->readLine();

				if ($line === false) {
					if(time() > $this->timeout){
						throw NetworkException::readTimeout();
					}

					return;
				}

				if (strlen($line) === 0) {
					$this->state = State::DONE;
				} else {
					$this->size = hexdec($line);
					$this->state = $this->size > 0
						? State::READING_BODY_CHUNKED_BODY
						: State::READING_BODY_CHUNKED_COMPLETION;

					$this->timeout = time() + $this->read_timeout;
				}
			}

			if($this->state === State::READING_BODY_CHUNKED_BODY) {

				$body = $this->socket->readSpecificSize(
					$this->size + 2);

				if ($body === false) {
					if(time() > $this->timeout){
						throw NetworkException::readTimeout();
					}

					return;
				}

				$this->body[] = substr($body,0, -2);
				$this->state = State::READING_BODY_CHUNKED_SIZE;
				$this->timeout = time() + $this->read_timeout;
			}

			if($this->state === State::READING_BODY_CHUNKED_COMPLETION){
				$body = $this->socket->readSpecificSize(2);

				if ($body === false) {
					if(time() > $this->timeout){
						throw NetworkException::readTimeout();
					}

					return;
				}

				$this->state = State::DONE;
			}

			if ($this->state === State::READING_BODY_TO_END) {
				$body = $this->socket->readToEnd();
				if ($body === false) {
					if(time() > $this->timeout){
						throw NetworkException::readTimeout();
					}

					return;
				}

				$this->state = State::DONE;
			}
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

	public function getState(): State
	{
		return $this->state;
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
        $this->state = State::DONE;
		$this->clear();
    }

	public function clear():void{
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

		if(preg_match('/^HTTP\/(\d\.\d)\s+(\d+)\s+(.+)$/', $line, $matches) == false){
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

	public function __debugInfo(){
		return [
			'socket' => $this->socket,
			'buffer' => $this->buffer,

            'state' => $this->state,

			'version' => $this->version,
			'status' => $this->status,
			'code' => $this->code,

			'headers' => $this->headers,

			'size' => $this->size,
		];
	}

}