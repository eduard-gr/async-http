<?php

namespace Eg\AsyncHttp\Exception;

class ResponseException extends NetworkException
{
	public static function startLine(string|null $start_line):self{
		return new self(sprintf('The starting (%s) line does not match the pattern',
			$start_line));
	}
}