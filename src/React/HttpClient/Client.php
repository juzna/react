<?php

namespace React\HttpClient;

use React\EventLoop\LoopInterface;
use React\HttpClient\Request;
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
		    throw new \InvalidArgumentException("Unknown data format");
	    }

        $requestData = new RequestData($method, $url, $headers);
        $connectionManager = $this->getConnectorForScheme($requestData->getScheme());
        $request = new Request($this->loop, $connectionManager, $requestData);

		if (isset($rawPostData)) {
			$request->write($rawPostData);
		}

		return $request;
    }

    public function get($url, array $headers = array())
    {
        return $this->request('GET', $url, NULL, $headers)->getResponseBody();
    }

	public function post($url, $data)
	{
		return $this->request('POST', $url, $data)->getResponseBody();
	}

    private function getConnectorForScheme($scheme)
    {
        return ('https' === $scheme) ? $this->secureConnector : $this->connector;
    }
}

