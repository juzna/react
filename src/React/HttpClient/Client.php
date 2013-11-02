<?php

namespace React\HttpClient;

use React\EventLoop\LoopInterface;
use React\HttpClient\Request;
use React\Promise\PromiseInterface;
use React\SocketClient\ConnectorInterface;

class Client
{
    private $loop;
    private $connectionManager;
    private $secureConnectionManager;

    public function __construct(LoopInterface $loop, ConnectorInterface $connector, ConnectorInterface $secureConnector)
    {
        $this->loop = $loop;
        $this->connector = $connector;
        $this->secureConnector = $secureConnector;
    }


	/**
	 * @param string $method
	 * @param string $url
	 * @param array|string $data
	 * @param array $headers
	 * @return PromiseInterface<string, Response> Promise for the response data, and the response as well
	 */
	public function request($method, $url, $data = NULL, array $headers = array())
    {
	    // Prepare POST ?
	    if (is_array($data)) {
		    $rawPostData = http_build_query($data);
		    $headers += array(
			    'Content-Type' => 'application/x-www-form-urlencoded',
			    'Content-Length' => strlen($rawPostData),
		    );

	    } elseif (is_string($data)) {
		    $rawPostData = $data;

	    } elseif ($data) {
		    throw new \InvalidArgumentException("Unknown data format; expected array or string");
	    }

        $requestData = new RequestData($method, $url, $headers);
        $connectionManager = $this->getConnectorForScheme($requestData->getScheme());
        $request = new Request($this->loop, $connectionManager, $requestData);

		if (isset($rawPostData)) {
			$request->on('headers-written', function() use ($request, $rawPostData) {
				$request->write($rawPostData);
				$request->end();
			});
			$request->writeHead();

		} else {
			$request->end(); // send the request now
		}

		return $request->getResponseBody()->then(function($d) use ($headers) {
			/** @var Response $response */
			list ($data, $response) = $d;

			if (preg_match('/^3\d\d$/', $response->getCode()) && ($location = $response->getHeader('Location'))) { // redirect
				unset($headers['Content-Type'], $headers['Content-Length']); // keep header, but these
				return $this->get($location, $headers); // another promise

            } else {
				return $d; // just return it

			}
		});
    }

    public function get($url, array $headers = array())
    {
        return $this->request('GET', $url, NULL, $headers);
    }

	public function post($url, $data)
	{
		return $this->request('POST', $url, $data);
	}

    private function getConnectorForScheme($scheme)
    {
        return ('https' === $scheme) ? $this->secureConnector : $this->connector;
    }
}

