<?php

namespace Eg\AsyncHttp\Exception;

class NetworkException extends TransferException
{
	public static function writeSizeException():self
    {
		return new self('the size sent does not match the size of payload');
	}

	public static function readTimeout():self
    {
		return new self('read data timed out');
	}

	public static function writeTimeout():self
    {
		return new self('write data timed out');
	}

	public static function connectionTimeout():self
    {
		return new self('connection timed out');
	}

	public static function socketSelectTimeout():self
    {
		return new self('socket select timeout');
	}

    public static function failedToSend():self
    {
        return new self('failed to send data');
    }
    public static function failedToRead():self
    {
        return new self('failed to read data');
    }

    public static function connectionResetByPeer():self
    {
        return new self('connection reset by peer');
    }

}