<?php

namespace Eg\AsyncHttp;

use Eg\AsyncHttp\Buffer\BufferInterface;
use Eg\AsyncHttp\Exception\NetworkException;
use Fiber;
use RuntimeException;
use Psr\Http\Message\UriInterface;

class Socket
{
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
    private ?string $ip;

	/**
	 * @var int How long in microsseconds select sleep
	 */
	private int $select_usleep = 0;

	/**
	 * @var int How long in seconds do we wait for socket select?
	 */
	private int $select_timeout = 0;

	/**
	 * @var int How long in seconds do we wait for data from the server?
	 */
	private int $read_timeout = 0;

	private int $read_length = 0;

    public function __construct(
		UriInterface $uri,
        BufferInterface $buffer,
        string $ip = null,

		//1/100 of seconds
		int $select_usleep = 10000,

		//30 seconds
		int $select_timeout = 30,

		//120 seconds
		int $read_timeout = 120,

		int $read_length = 4 * 1024
	){
        $this->ip = $ip;
        $this->buffer = $buffer;

		$this->select_usleep = $select_usleep;
		$this->select_timeout = $select_timeout;

		$this->read_timeout = $read_timeout;
		$this->read_length = $read_length;

		$this->setSocketOpen($uri);
	}

	public function __destruct()
	{
		if($this->socket){
			fclose($this->socket);
			$this->socket = null;
		}
	}

	private function getPlainConnection(
		UriInterface $uri
	):array
	{
        $options = [];

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
                'disable_compression' => false
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

		$this->socket = stream_socket_client(
			address: $address,
			error_code: $error_code,
			error_message: $error_message,
			timeout: null,
			flags: STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
			context: $context);

		if($error_code !== 0 ){
			throw new NetworkException($error_message, $error_code);
		}

//		stream_set_chunk_size($socket, 1024);
//		stream_set_read_buffer($socket, 1024);
//		stream_set_write_buffer($socket, 1024);
		stream_set_blocking($this->socket, false);
	}


	private function isReadyToWrite():bool{

		if($this->is_ready_to_write){
			return true;
		}

		$socket = $this->socket;

		/**
		 * We are waiting for when it will be possible to write to the socket
		 */
		$timeout = time() + $this->select_timeout;
		do {
			$reader = null;
			$writer = [$socket];
			$except = null;

			$selected = stream_select(
				read: $reader,
				write: $writer,
				except: $except,
				seconds: 0,
				microseconds: $this->select_usleep);

			if(empty($writer) === false) {
				$this->is_ready_to_write = true;
				return true;
			}

			if(time() > $timeout){
				throw NetworkException::socketSelectTimeout();
			}

			Fiber::suspend();
		}while(true);
	}

	private function isReadyToRead():bool{

		if($this->is_ready_to_read){
			return true;
		}

		$socket = $this->socket;

		/**
		 * We are waiting for when it will be possible to write to the socket
		 */
		$timeout = time() + $this->select_timeout;
		do {
			$reader = [$socket];
			$writer = null;
			$except = null;

			$selected = stream_select(
				read: $reader,
				write: $writer,
				except: $except,
				seconds: 0,
				microseconds: $this->select_usleep);

			if(empty($reader) === false) {
				$this->is_ready_to_read = true;
				return true;
			}

			if(time() > $timeout){
				throw NetworkException::socketSelectTimeout();
			}

			//TODO: Check select timeout
			Fiber::suspend();
		}while(true);
	}

	/**
	 * @throws \Throwable
	 */
	public function send(
		string $payload
	):void
	{
        $this->buffer->reset();

		$this->isReadyToWrite();

		$fwrite = fwrite($this->socket, $payload);

        if($fwrite === false){
            throw NetworkException::failedToSend();
        }

		if($fwrite != strlen($payload)){
			throw NetworkException::writeSizeException();
		}
	}

	public function readSpecificSize(int $size):string
	{
		$this->isReadyToRead();

		$timeout = time() + $this->read_timeout;

		while($this->buffer->size() < $size){
			$fragment = fread(
				stream: $this->socket,
				length: $this->read_length);

			if($fragment === false){
				throw new RuntimeException('TODO');
			}

			if(strlen($fragment) === 0){
				if(time() > $timeout){
					throw NetworkException::readTimeout();
				}

				Fiber::suspend();
				continue;
			}

			$this->buffer->append($fragment);
		}

		return $this->buffer->read($size);
	}

	public function readToEnd():string{

		$this->isReadyToRead();
		do{
			$fragment = fread(
				stream: $this->socket,
				length: $this->read_length);

			if(strlen($fragment) > 0){
                $this->buffer->append($fragment);
			}

		}while(feof($this->socket) == false);

		return $this->buffer->read($this->buffer->size());
	}

	public function readLine():string
	{
		$line = $this->buffer->readLine();
		if($line !== null){
			return $line;
		}

		$this->isReadyToRead();

		$timeout = time() + $this->read_timeout;

		do{
			$fragment = fread(
				stream: $this->socket,
				length: $this->read_length);

			if($fragment === false){
				throw new RuntimeException('TODO');
			}

			if(strlen($fragment) === 0){
				if(time() > $timeout){
					throw NetworkException::readTimeout();
				}

				Fiber::suspend();
				continue;
			}

			$timeout = time() + $this->read_timeout;
            $this->buffer->append($fragment);

			$line = $this->buffer->readLine();
			if($line !== null){
				return $line;
			}

		}while(true);
	}
}