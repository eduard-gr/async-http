<?php

namespace Eg\AsyncHttp;

use Eg\AsyncHttp\Buffer\BufferInterface;
use Eg\AsyncHttp\Exception\NetworkException;
use Fiber;
use Generator;
use RuntimeException;
use Psr\Http\Message\UriInterface;
use Throwable;

class Socket
{

    const READ_LENGTH = 8 * 1024;
	/**
	 * @var resource
	 */
	private $socket = null;

    private BufferInterface $buffer;

	private bool $is_ready_to_read = false;
	private bool $is_ready_to_write = false;

    /**
     * @var string|null IP address from which the connection will be made
     */
    private string|null $ip;

	/**
	 * @var int How long in microsseconds select wait
	 */
	private int $select_usleep = 0;

	/**
	 * @var int How long in seconds connection timeout (read/write)
	 */
	private int $timeout;

    public function __construct(
		UriInterface $uri,
        BufferInterface $buffer,

        string|null $ip = null,

		//1/100 of seconds
		int $select_usleep = 10000,
		int $timeout = 120
	){
        $this->ip = $ip;
        $this->buffer = $buffer;
		$this->select_usleep = $select_usleep;
		$this->timeout = $timeout;
		$this->setSocketOpen($uri);
	}

	public function __destruct()
	{
		if($this->socket){
			stream_socket_shutdown(
				stream: $this->socket,
				mode: STREAM_SHUT_RDWR);

			fclose($this->socket);
			$this->socket = null;
		}
	}

	private function getPlainConnection(
		UriInterface $uri
	):array
	{
		$options = [
			'socket' => [
				'connect_timeout' => 5,
				'read_timeout' => [
					'sec'  => $this->timeout,
					'usec' => 0,
				],
				'write_timeout' => [
					'sec'  => $this->timeout,
					'usec' => 0,
				]
			]
		];

        if(empty($this->ip) === false){
            $options['socket'] = [
                'bindto' => sprintf('%s:0',
                    $this->ip)
            ];
        }

		return [
			sprintf('tcp://%s:%s',
				$uri->getHost(),
				$uri->getPort() ?? 80),
			stream_context_create($options)
		];
	}

	private function getSecureConnection(
		UriInterface $uri
	):array
	{
        $options = [
            'ssl' => [
                'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                'capture_peer_cert' => false,
                'capture_peer_chain' => false,
                'capture_peer_cert_chain' => false,
                'verify_host' => false,
                'verify_peer' => false,
                'verify_depth' => false,
                'allow_self_signed' => true,
                'disable_compression' => false,
				'timeout' => 5
            ],
			'socket' => [
				'connect_timeout' => 5,
				'tcp_nodelay' => true,
				'read_timeout' => [
					'sec'  => $this->timeout,
					'usec' => 0,
				],
				'write_timeout' => [
					'sec'  => $this->timeout,
					'usec' => 0,
				]
			]
        ];

        if(empty($this->ip) === false){
            $options['socket'] = [
                'bindto' => sprintf('%s:0',
                    $this->ip)
            ];
        }

		return [
			sprintf('ssl://%s:%s',
				$uri->getHost(),
				$uri->getPort() ?? 443),
			stream_context_create($options)
		];
	}

	private function setSocketOpen(
		UriInterface $uri
	):void
	{
		[$address, $context] = match($uri->getScheme()){
			'http' => $this->getPlainConnection($uri),
			'https' => $this->getSecureConnection($uri)
		};

		try{
			$this->socket = stream_socket_client(
				address: $address,
				error_code: $error_code,
				error_message: $error_message,
				timeout: 5,
				flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
				context: $context);
		}catch(Throwable $e){
			throw new NetworkException($e->getMessage(), $e->getCode());
		}

		if($error_code !== 0 ){
			throw new NetworkException($error_message, $error_code);
		}

		stream_set_blocking($this->socket, false);
	}

	public function isReadyToWrite():bool{

		if($this->is_ready_to_write){
			return true;
		}

        $reader = null;
        $writer = [$this->socket];
        $except = null;

        $selected = stream_select(
            read: $reader,
            write: $writer,
            except: $except,
            seconds: 0,
            microseconds: $this->select_usleep);

        if($selected === false){
            throw NetworkException::socketSelectTimeout();
        }

        if($selected) {
            return $this->is_ready_to_write = true;
        }

        return false;

	}

    public function isReadyToRead():bool{

		if($this->is_ready_to_read){
			return true;
		}

        $reader = [$this->socket];
        $writer = null;
        $except = null;

        $selected = stream_select(
            read: $reader,
            write: $writer,
            except: $except,
            seconds: 0,
            microseconds: $this->select_usleep);

        if($selected === false){
            throw NetworkException::socketSelectTimeout();
        }

        if($selected) {
            return $this->is_ready_to_read = true;
        }

        return false;
	}

    /**
     * @param string $payload
     * @return int
     * 0 - waiting socket
     * 1 - send
     */
	public function send(
		string $payload
	):int
	{
        $this->buffer->reset();

		if($this->isReadyToWrite() === false){
            return 0;
        }

		$result = fwrite($this->socket, $payload);

        if($result === false){
            throw NetworkException::failedToSend();
        }

		if($result != strlen($payload)){
			throw NetworkException::writeSizeException();
		}

        return 1;
	}

	public function readSpecificSize(
        int $size
    ):string|bool
	{
        if($this->isReadyToRead() !== true){
            return false;
        }

		if($this->buffer->size() >= $size){
			return $this->buffer->read($size);
		}

        $fragment = fread(
            stream: $this->socket,
            length: $size);

        if($fragment === false){
            throw NetworkException::failedToRead();
        }

        if(strlen($fragment) === 0){
            return false;
        }

        $this->buffer->append($fragment);

        if($this->buffer->size() < $size){
            return false;
        }

		return $this->buffer->read($size);
	}

	public function readToEnd():string|bool
    {
        if($this->isReadyToRead() === false){
            return false;
        }

        $fragment = fread(
            stream: $this->socket,
            length: self::READ_LENGTH);

        if($fragment === false){
            throw NetworkException::failedToRead();
        }

        if(strlen($fragment) > 0){
			$this->buffer->append($fragment);
        }

        if(feof($this->socket) === false){
            return false;
        }

        return $this->buffer->read($this->buffer->size());
	}

	public function readLine(
        int $length = self::READ_LENGTH
    ):string|false
	{
        if($this->isReadyToRead() === false){
            return false;
        }

		$line = $this->buffer->readLine();

		if($line !== null){
            return $line;
		}

        $fragment = fread(
            stream: $this->socket,
            length: $length);

        if($fragment === false){
            throw NetworkException::failedToRead();
        }

        if(strlen($fragment) === 0){
            return false;
        }

        $this->buffer->append($fragment);

        $line = $this->buffer->readLine();
        if($line === null){
            return false;
        }

        return $line;
	}

	public function __debugInfo(){
		return [
			'is_ready_to_read' => $this->is_ready_to_read,
			'is_ready_to_write' => $this->is_ready_to_write,
		];
	}
}