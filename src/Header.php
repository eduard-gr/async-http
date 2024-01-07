<?php

namespace Eg\AsyncHttp;

class Header
{

	private array $payload;

	public function __construct(array $payload)
	{
		$this->payload = $payload;
	}

	public function getArray():array
	{
		return $this->payload;
	}

	private function find($key):mixed{
		foreach($this->payload as $name => $value){
			if(strcasecmp($key, $name) === 0){
				return $value;
			}
		}

		return null;
	}

	public function getConnection():string|null
	{
		return $this->find('Connection');
	}

	public function getContentLength():int|null
	{
		return $this->find('Content-Length');
	}

	public function getTransferEncoding():string|null
	{
		return $this->find('Transfer-Encoding');
	}
}