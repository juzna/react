<?php

namespace React\HttpClient;

use Evenement\EventEmitter;
use Guzzle\Parser\Message\MessageParser;
use React\EventLoop\LoopInterface;
use React\HttpClient\Response;
use React\HttpClient\ResponseHeaderParser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\SocketClient\ConnectorInterface;
use React\Stream\BufferedSink;
use React\Stream\Stream;
use React\Stream\WritableStreamInterface;

/**
 * @event headers-written
 * @event response
 */
class Request extends EventEmitter implements WritableStreamInterface
{
    const STATE_INIT = 0;
    const STATE_WRITING_HEAD = 1;
    const STATE_HEAD_WRITTEN = 2;
    const STATE_END = 3;

    private $loop;
    private $connector;
    private $requestData;

	/** @var Stream */
    private $stream;
    private $buffer;
    private $responseFactory;
    private $response;
    private $state = self::STATE_INIT;

    public function __construct(LoopInterface $loop, ConnectorInterface $connector, RequestData $requestData)
    {
        $this->loop = $loop;
        $this->connector = $connector;
        $this->requestData = $requestData;
    }

    public function isWritable()
    {
        return self::STATE_END > $this->state;
    }

    public function writeHead()
    {
        if (self::STATE_WRITING_HEAD <= $this->state) {
            throw new \LogicException('Headers already written');
        }

        $this->state = self::STATE_WRITING_HEAD;

        $requestData = $this->requestData;
        $streamRef = &$this->stream;
        $stateRef = &$this->state;

        $this
            ->connect()
            ->then(
                function ($stream) use ($requestData, &$streamRef, &$stateRef) {
                    $streamRef = $stream;

                    $stream->on('drain', array($this, 'handleDrain'));
                    $stream->on('data', array($this, 'handleData'));
                    $stream->on('end', array($this, 'handleEnd'));
                    $stream->on('error', array($this, 'handleError'));

                    $requestData->setProtocolVersion('1.0');
                    $headers = (string) $requestData;

                    $stream->write($headers);

                    $stateRef = Request::STATE_HEAD_WRITTEN;

                    $this->emit('headers-written', array($this));
                },
                array($this, 'handleError')
            );
    }

    public function write($data)
    {
        if (!$this->isWritable()) {
            return;
        }

        if (self::STATE_HEAD_WRITTEN <= $this->state) {
            return $this->stream->write($data);
        }

        $this->on('headers-written', function ($this) use ($data) {
            $this->write($data);
        });

        if (self::STATE_WRITING_HEAD > $this->state) {
            $this->writeHead();
        }

        return false;
    }

    public function end($data = null)
    {
        if (null !== $data && !is_scalar($data)) {
            throw new \InvalidArgumentException('$data must be null or scalar');
        }

        if (null !== $data) {
            $this->write($data);
        } else if (self::STATE_WRITING_HEAD > $this->state) {
            $this->writeHead();
        }
    }

    public function handleDrain()
    {
        $this->emit('drain', array($this));
    }

    public function handleData($data)
    {
        $this->buffer .= $data;

        if (false !== strpos($this->buffer, "\r\n\r\n")) {
            list($response, $bodyChunk) = $this->parseResponse($this->buffer);

            $this->buffer = null;

            $this->stream->removeListener('drain', array($this, 'handleDrain'));
            $this->stream->removeListener('data', array($this, 'handleData'));
            $this->stream->removeListener('end', array($this, 'handleEnd'));
            $this->stream->removeListener('error', array($this, 'handleError'));

            $this->response = $response;

            $response->on('end', function () {
                $this->close();
            });
            $response->on('error', function (\Exception $error) {
                $this->closeError(new \RuntimeException(
                    "An error occured in the response",
                    0,
                    $error
                ));
            });

            $this->emit('response', array($response, $this));

            $response->emit('data', array($bodyChunk));
        }
    }

    public function handleEnd()
    {
        $this->closeError(new \RuntimeException(
            "Connection closed before receiving response"
        ));
    }

    public function handleError($error)
    {
        $this->closeError(new \RuntimeException(
            "An error occurred in the underlying stream",
            0,
            $error
        ));
    }

    public function closeError(\Exception $error)
    {
        if (self::STATE_END <= $this->state) {
            return;
        }
        $this->emit('error', array($error, $this));
        $this->close($error);
    }

    public function close(\Exception $error = null)
    {
        if (self::STATE_END <= $this->state) {
            return;
        }

        $this->state = self::STATE_END;

        if ($this->stream) {
            $this->stream->close();
        }

        $this->emit('end', array($error, $this->response, $this));
    }

    protected function parseResponse($data)
    {
        $parser = new MessageParser();
        $parsed = $parser->parseResponse($data);

        $factory = $this->getResponseFactory();

        $response = $factory(
            $parsed['protocol'],
            $parsed['version'],
            $parsed['code'],
            $parsed['reason_phrase'],
            $parsed['headers']
        );

        return array($response, $parsed['body']);
    }

    protected function connect()
    {
        $host = $this->requestData->getHost();
        $port = $this->requestData->getPort();

        return $this->connector
            ->create($host, $port);
    }

    public function setResponseFactory($factory)
    {
        $this->responseFactory = $factory;
    }

    public function getResponseFactory()
    {
        if (null === $factory = $this->responseFactory) {
            $loop = $this->loop;
            $stream = $this->stream;

            $factory = function ($protocol, $version, $code, $reasonPhrase, $headers) use ($loop, $stream) {
                return new Response(
                    $loop,
                    $stream,
                    $protocol,
                    $version,
                    $code,
                    $reasonPhrase,
                    $headers
                );
            };

            $this->responseFactory = $factory;
        }

        return $factory;
    }


	/**
	 * @return PromiseInterface<string, Response>
	 */
	public function getResponseBody()
    {
        $deferred = new Deferred;
        $resolver = $deferred->resolver();
        $hasResponse = false;

        $this->on('response', function (Response $response) use ($deferred, &$hasResponse) {
            $hasResponse = true;
            BufferedSink::createPromise($response)->then(
                function($data) use ($deferred, $response) {
                    $deferred->resolve( [ $data, $response ] );
                },
                function($err) use ($deferred) {
                    $deferred->reject($err);
                }
            );
        });
        $this->on('end', function($err) use ($resolver, &$hasResponse) {
            if (!$hasResponse) {
                $resolver->reject($err ?: new \RuntimeException("No data received"));
            }
        });

        return $deferred->promise();
	}
}

