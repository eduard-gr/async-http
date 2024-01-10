<?php

namespace Eg\AsyncHttp\Test;

use Eg\AsyncHttp\Client as AsyncHttp;
use Fiber;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use Iterator;

class RequestTest2 extends Bootstrap
{

	/**
	 * @throws \Throwable
	 */
	public function testRequest():void
	{
		$parameters = [
            'samo_action' => 'api',
            'version' => '1.0',
            'type' => 'json',

            'oauth_token' => '03c0b67bc82b489a90556af872f23e33',

            'action' => 'SearchTour_PRICES',

            'TOWNFROMINC' => 3164,
            'STATEINC' => 9,

            'CHECKIN_BEG' => '20240201',
            'CHECKIN_END' =>'20240220',

            'NIGHTS_FROM' => 7,
            'NIGHTS_TILL' => 14,

            'ADULT' => 2,
            'CHILD' => 0,

            'CURRENCY' => 2,

            'PRICEPAGE' => 1
		];


        $fiber = new Fiber(callback: function() use ($parameters): ?Iterator {
            $client = new AsyncHttp();

            $request = new Request(
                method:'GET',
                uri: sprintf('https://online.joinupbaltic.eu/export/default.php?%s',
                    http_build_query($parameters, null, '&')),
                headers:[
                    //TODO Do we need user agents ?
                    //'User-Agent' => 'GuzzleHttp/7',
                    'Accept' => 'application/json',
                    'Connection' => 'keep-alive'
                ],
                body: null);

            $response = $client->send($request);
            $body = $response->getBody()->getContents();

            json_decode($response->getBody()->getContents(), true);
            if(json_last_error() != JSON_ERROR_NONE) {
                var_export(json_last_error_msg());
            }

            file_put_contents('result.json', $body);
            //$response->getBody()->getContents();

            return null;
        });

        while(true){
            if($fiber->isStarted() == false){
                $fiber->start();
            }


            $result = $fiber->isSuspended()
                ? $fiber->resume()
                : null;

            if($fiber->isTerminated()){
                break;
            }
        }

		$host = 'http://127.0.0.1:8080';

		$url = sprintf('%s/tariffsearch/getResult?%s',
			$host,
			http_build_query(
				$parameters,
				null,
				'&'));



		//$client = new GuzzleClient();
		$client = new AsyncHttp();

	}

}