<?php

namespace Eg\AsyncHttp\Test;

use DI\Container;
use DI\ContainerBuilder;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class Bootstrap extends TestCase
{
	private Container $container;

	public function __construct($name = null, array $data = [], $dataName = '')
	{
		parent::__construct($name, $data, $dataName);

		$builder = new ContainerBuilder();
		$builder->addDefinitions([
			LoggerInterface::class => function(){
				$logger = new Logger('AsyncHttp');
				$logger->pushHandler(new ErrorLogHandler());
				return $logger;
			},
		]);

		$this->container = $builder->build();
	}


	protected function set($name, $value):void {
		$this->container->set($name, $value);
	}


	protected function get($name){
		return $this->container->get($name);
	}
}