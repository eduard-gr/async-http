<?php

namespace Eg\AsyncHttp\Test;

use Eg\AsyncHttp\Client as AsyncHttp;
use Fiber;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use Iterator;

class RequestTest3 extends Bootstrap
{

	/**
	 * @throws \Throwable
	 */
	public function testRequest():void
	{

        $fiber = new Fiber(callback: function(): ?Iterator {
            $client = new AsyncHttp();

            $request = new Request(
                method:'GET',
                uri: 'http://127.0.0.1:8080',
                headers:[
                    //TODO Do we need user agents ?
                    //'User-Agent' => 'GuzzleHttp/7',
                    'Accept' => 'application/json',
                    'Connection' => 'keep-alive'
                ],
                body: null);

            $response = $client->send($request);

            $json = json_decode($response->getBody(), true);
            if(json_last_error() != JSON_ERROR_NONE) {
                var_export(json_last_error_msg());
            }

            var_export($json);
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
	}

}