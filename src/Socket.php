<?php

namespace Eg\AsyncHttp;

use Eg\AsyncHttp\Buffer\BufferInterface;
use Eg\AsyncHttp\Exception\NetworkException;
use Fiber;
use Generator;
use RuntimeException;
use Psr\Http\Message\UriInterface;

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
    ):Generator
	{
        if($this->isReadyToRead() === false){
            yield;
        }

		$timeout = time() + $this->read_timeout;

		while($this->buffer->size() < $size){
			$fragment = fread(
				stream: $this->socket,
				length: self::READ_LENGTH);

			if($fragment === false){
                throw NetworkException::failedToRead();
			}

			if(strlen($fragment) === 0){
				if(time() > $timeout){
					throw NetworkException::readTimeout();
				}

                yield;
				continue;
			}

			$this->buffer->append($fragment);
		}

		return $this->buffer->read($size);
	}

	public function readToEnd():Generator
    {
        if($this->isReadyToRead() === false){
            yield;
        }

        $timeout = time() + $this->read_timeout;

		do{
			$fragment = fread(
				stream: $this->socket,
				length: self::READ_LENGTH);

            if($fragment === false){
                throw NetworkException::failedToRead();
            }

            if(strlen($fragment) === 0){
                if(time() > $timeout){
                    throw NetworkException::readTimeout();
                }

                yield;
                continue;
            }

            $timeout = time() + $this->read_timeout;
            $this->buffer->append($fragment);
		}while(feof($this->socket) == false);

		return $this->buffer->read($this->buffer->size());
	}

	public function readLine(
        int $length = self::READ_LENGTH
    ):Generator
	{

        if($this->isReadyToRead() === false){
            yield;
        }

		$line = $this->buffer->readLine();
		if($line !== null){
            return $line;
		}

        $timeout = time() + $this->read_timeout;

        do{
            $fragment = fread(
                stream: $this->socket,
                length: $length);

            if($fragment === false){
                throw NetworkException::failedToRead();
            }

            if(strlen($fragment) === 0){
                if(time() > $timeout){
                    throw NetworkException::readTimeout();
                }

                yield;
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