<?php

namespace Eg\AsyncHttp\Exception;

class NetworkException extends TransferException
{
	public static function writeSizeException():self
    {
		return new self('The size sent does not match the size of payload');
	}

	public static function readTimeout():self
    {
		return new self('Read data timed out');
	}

	public static function socketSelectTimeout():self
    {
		return new self('Socket select timeout');
	}

    public static function failedToSend():self
    {
        return new self('failed to send data');
    }

    public static function failedToRead():self
    {
        return new self('failed to read data');
    }
}