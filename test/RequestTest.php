<?php

namespace Eg\AsyncHttp\Test;

use Eg\AsyncHttp\Client as AsyncHttp;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;

class RequestTest extends Bootstrap
{

	/**
	 * @throws \Throwable
	 */
	public function testRequest():void
	{
		$parameters = [
			'cityId'=>523,
			'countryId'=>5732,
			'after'=>'10.02.2024',
			'before'=>'24.02.2024',
			'priceMin'=>0,
			'priceMax'=>115000,
			'currency'=>18864,
			'nightsMin'=>6,
			'nightsMax'=>14,
			'accommodationId'=>2,
			'hotelClassId'=>2568,
			'hotelClassBetter'=>'true',
			'xml'=>'true',
			'locale'=>'en',
			'formatResult'=>'false',
			'version'=>2,
			'hotelInStop'=>'false',
		];

		$host = 'http://127.0.0.1:8080';

		$url = sprintf('%s/tariffsearch/getResult?%s',
			$host,
			http_build_query(
				$parameters,
				null,
				'&'));

		$request = new Request(
			method:'GET',
			uri: $url,
			headers:[
				//TODO Do we need user agents ?
				//'User-Agent' => 'GuzzleHttp/7',
				'Accept' => 'application/json',
				'Connection' => 'keep-alive'
			],
		    body: null);

		//$client = new GuzzleClient();
		$client = new AsyncHttp();
		$client->send($request);
	}

}