<?php

namespace Ukrbublik\ReactStreamingBird;
use React\HttpClient\Request;
use React\HttpClient\RequestData;
use React\SocketClient\ConnectorInterface;
use React\Promise\FulfilledPromise;
use React\Stream\Stream;

class HttpRequest extends Request
{
    public function __construct(ConnectorInterface $connector, RequestData $requestData, $stream = null)
    {
        parent::__construct($connector, $requestData);

        $this->stream = $stream;
    }

    protected function connect()
    {
        if ($this->stream)
            return new FulfilledPromise($this->stream);
        else
            return parent::connect();
    }
}

?>