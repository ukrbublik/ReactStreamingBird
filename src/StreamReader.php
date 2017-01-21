<?php

namespace Ukrbublik\ReactStreamingBird;

use Ukrbublik\ReactStreamingBird\Location\LocationInterface;
use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\SocketClient\ConnectorInterface;
use React\SocketClient\DnsConnector;
use React\SocketClient\SecureConnector;
use React\SocketClient\TimeoutConnector;
use React\SocketClient\TcpConnector;
use React\HttpClient\Request;
use React\HttpClient\RequestData;
use React\HttpClient\Response;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\RejectedPromise;
use Evenement\EventEmitter;

class StreamReader extends EventEmitter
{
    const MAX_RETRY_ATTEMPTS = 20;
    const MAX_CONNECT_ATTEMPTS = 10;
    const CONNECT_RETRY_TIME = 2;
    const CONNECT_TIMEOUT = 5;
    const RETRY_TIME = 10;
    const STALL_DETECT_TIME = 90;
    const USER_AGENT = 'TwitterStreamReader/1.0RC +https://github.com/owlycode/twitter-stream-reader';

    const METHOD_FILTER   = 'filter';
    const METHOD_SAMPLE   = 'sample';
    const METHOD_RETWEET  = 'retweet';
    const METHOD_FIREHOSE = 'firehose';
    const METHOD_LINKS    = 'links';
    const METHOD_USER     = 'user';
    const METHOD_SITE     = 'site';

    protected $endpoints = [
        'site'     => 'https://sitestream.twitter.com/1.1/site.json',
        'user'     => 'https://userstream.twitter.com/1.1/user.json',
        'filter'   => 'https://stream.twitter.com/1.1/statuses/filter.json',
        'sample'   => 'https://stream.twitter.com/1.1/statuses/sample.json',
        'retweet'  => 'https://stream.twitter.com/1.1/statuses/retweet.json',
        'firehose' => 'https://stream.twitter.com/1.1/statuses/firehose.json',
        'links'    => 'https://stream.twitter.com/1.1/statuses/links.json'
    ];

    /*** @var ConnectorInterface */
    protected $connector;

    /*** @var Stream */
    protected $stream;

    /*** @var LoopInterface */
    protected $loop;

    /*** @var string */
    protected $buffer;

    /*** @var Oauth */
    protected $oauth;

    /*** @var Monitor */
    protected $monitor;

    /*** @var int */
    private $lastStreamActivity;

    /*** @var TimerInterface */
    private $stallTimer = null;

    /*** @var Request */
    private $request;

    /*** @var Response */
    private $response;

    /*** @var int */
    protected $readRetryCount = 0;
    /*** @var int */
    protected $connectRetryCount = 0;


    /**
    * @internal Moved from being a const to a variable, because some methods (user and site) need to change it.
    */
    protected $baseUrl = 'https://stream.twitter.com/1.1/statuses/';

    protected $method;
    protected $count; //Can be -150,000 to 150,000. @see http://dev.twitter.com/pages/streaming_api_methods#count
    protected $followIds;
    protected $trackWords;
    protected $location;

    /**
     * @param LoopInterface  $loop
     * @param Oauth      $oauth
     * @param string     $method
     * @param boolean    $lang
     */
    public function __construct(LoopInterface $loop, Oauth $oauth, $method = AbstractStream::METHOD_SAMPLE, $lang = false)
    {
        $this->monitor = new Monitor();

        $this->monitor->register(Monitor::TYPE_MAX, 'max_idle_time', 0);
        $this->monitor->register(Monitor::TYPE_LAST, 'idle_time', 0);
        $this->monitor->register(Monitor::TYPE_COUNT, 'tweets');

        $this->oauth  = $oauth;
        $this->method = $method;
        $this->lang   = $lang;

        $this->loop = $loop;
    }

    /**
     * Stop reading stream
     */
    public function stop() {
        $this->buffer = '';
        if ($this->request) {
            $this->request->close();
            $this->request = null;
        }
        if ($this->response) {
            $this->response->close();
            $this->response = null;
        }
        $this->removeAllListeners('tweet');
        $this->removeAllListeners('error');
        $this->removeAllListeners('warning');
    }

    /**
     * Connect and read from stream
     */
    public function openAsync()
    {
        $url      = $this->endpoints[$this->method];
        $urlParts = parse_url($url);
        $scheme   = $urlParts['scheme'] == 'https' ? 'ssl://' : 'tcp://';
        $port     = $urlParts['scheme'] == 'https' ? 443 : 80;
        $isSecure = ($urlParts['scheme'] == 'https');

        $requestParams = [];

        if ($this->lang) {
            $requestParams['language'] = $this->lang;
        }

        if (($this->method === self::METHOD_FILTER || $this->method === self::METHOD_USER) && count($this->trackWords) > 0) {
            $requestParams['track'] = implode(',', $this->trackWords);
        }
        if (($this->method === self::METHOD_FILTER || $this->method === self::METHOD_SITE) && count($this->followIds) > 0) {
            $requestParams['follow'] = implode(',', $this->followIds);
        }

        if ($this->method === self::METHOD_FILTER && $this->location) {
            $requestParams['locations'] = implode(',', $this->location->getBoundingBox());
        }

        if ($this->count <> 0) {
            $requestParams['count'] = $this->count;
        }

        $this->stop();
        $this->connectAsync($isSecure, $urlParts['host'], $port)
        ->then(function() use ($url, $requestParams) {
            $this->connectRetryCount = 0;
            return $this->readFromStream
              ($url, $requestParams, $this->oauth->getAuthorizationHeader($url, $requestParams))
            ->then(function($res) {
                $this->readRetryCount++;
                $err = null;
                if ($res['type'] == 'http')
                    $err = new TwitterException(sprintf('Twitter API responsed a "%s" status code.', $res['code']));
                else if ($res['type'] == 'stalled')
                    $err = new \TwitterException("Stalled");
                else if ($res['type'] == 'disconnected')
                    $err = new \TwitterException("Disconnected");
                else if ($res['type'] == 'err')
                    $err = $res['err'];
                if ($this->readRetryCount >= self::MAX_RETRY_ATTEMPTS) {
                    $this->readRetryCount = 0;
                    $this->emit("error", [$err]);
                } else {
                  $this->emit("warning", [$err]);
                  $time = ($res['type'] == 'http' ? self::RETRY_TIME : 0.5);
                  $this->loop->addTimer($time, function() {
                      $this->openAsync();
                  });
                }
            }, function($err) {
                $this->readRetryCount = 0;
                $this->emit("error", [$err]);
            });
        }, function($err) {
            $this->connectRetryCount = 0;
            $this->emit("error", [$err]);
        });
    }

    /**
     * @param bool    $isSecure
     * @param string  $host
     * @param integer $port
     * @return Promise
     */
    protected function connectAsync($isSecure, $host, $port)
    {
        $tcpConnector = new TcpConnector($this->loop);
        $dnsResolverFactory = new \React\Dns\Resolver\Factory();
        $this->dns = $dnsResolverFactory->createCached('8.8.8.8', $this->loop);
        $dnsConnector = new DnsConnector($tcpConnector, $this->dns);
        $connector = new TimeoutConnector($dnsConnector, self::CONNECT_TIMEOUT, $this->loop);
        $secureConnector = new TimeoutConnector(
          new SecureConnector($dnsConnector, $this->loop), self::CONNECT_TIMEOUT, $this->loop);
        $this->connector = ($isSecure ? $secureConnector : $connector);

        $deferred = new Deferred();
        $this->connector->create($host, $port)->then(function($stream) use ($deferred) {
            $this->stream = $stream;
            $deferred->resolve();
        }, function($err) use ($deferred, $isSecure, $host, $port) {
            if ($err instanceof \RuntimeException) {
                $this->connectRetryCount++;
                if ($this->connectRetryCount >= 1 /*self::MAX_CONNECT_ATTEMPTS*/) {
                    $deferred->reject($err);
                } else {
                    $this->loop->addTimer(self::CONNECT_RETRY_TIME, 
                      function() use ($deferred, $isSecure, $host, $port) {
                        $promise = $this->connectAsync($isSecure, $host, $port);
                        $promise->then(function() use ($deferred) {
                            $deferred->resolve();
                        }, function($err) use ($deferred) {
                            $deferred->reject($err);
                        });
                    });
                }
            } else {
                $deferred->reject($err);
            }
        });

        return $deferred->promise();
    }

    /**
     * @param string $url
     * @param array  $params
     * @param string $credentials
     * @return Promise
     */
    protected function readFromStream($url, array $params, $credentials)
    {
        $deferred = new Deferred();

        $urlParts = parse_url($url);
        $postData = http_build_query($params, null, '&', PHP_QUERY_RFC3986);
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Content-Length' => strlen($postData),
            'Accept' => '*/*',
            'Authorization' => $credentials,
            'User-Agent' => self::USER_AGENT,
        ];

        $reqData = new RequestData('POST', $url, $headers, '1.1');
        $this->request = new HttpRequest($this->connector, $reqData, $this->stream);

        $this->addStallDetectTimer(function () use ($deferred) {
            //Stalled
            $this->stop();
            $deferred->resolve(['type' => 'stalled']);
        });
        $this->request->on('response', function ($response) use ($deferred) {
            $this->readRetryCount = 0;
            $this->response = $response;
            if ($response->getCode() != 200) {
                $this->stop();
                if (in_array($response->getCode(), [420, 410, 429]) 
                  || $response->getCode() >= 500) {
                    //Got HTTP retryable status
                    $deferred->resolve(['type' => 'http', 'code' => $response->getCode()]);
                } else {
                    $deferred->reject(new TwitterException(sprintf('Twitter API responsed a "%s" status code.', $response->getCode())));
                }
            } else {
                $response->on('data', function ($data, $response) use ($deferred) {
                    $this->buffer .= $data;
                    $this->processBuffer();
                    $this->addStallDetectTimer(function () use ($deferred) {
                        //Stalled
                        $this->stop();
                        $deferred->resolve(['type' => 'stalled']);
                    });
                });
                $response->on('error', function($err) use ($deferred) {
                    $deferred->resolve(['type' => 'err', 'err' => $err]);
                });
                $response->on('end', function() use ($deferred) {
                    //Disconnected
                    $deferred->resolve(['type' => 'disconnected']);
                });
            }
        });

        $this->request->on('error', function($err) use ($deferred) {
            $deferred->resolve(['type' => 'err', 'err' => $err]);
        });

        $this->request->end($postData);

        return $deferred->promise()->always(function() {
            $this->stop();
        });
    }

    protected function addStallDetectTimer(callable $onStalled) 
    {
        $this->lastStreamActivity = time();
        if ($this->stallTimer !== null)
            $this->stallTimer->cancel();
        $this->stallTimer = $this->loop->addTimer(self::STALL_DETECT_TIME, 
          function() {
            $idle = (time() - $this->lastStreamActivity);
            if ($idle >= self::STALL_DETECT_TIME) {
                //Stall detected
                call_user_func_array($onStalled, []);
            }
        });
    }

    protected function processBuffer() 
    {
        $delim = "\r\n";
        while(($pos = strpos($this->buffer, $delim)) !== false) {
            $str = substr($this->buffer, 0, $pos);
            if ($str !== '') {
                $tweet = json_decode($str, true);
                $this->processTweet($tweet);
            }
            $this->buffer = substr($this->buffer, $pos + strlen($delim));
        }
    }

    /**
     * 
     */
    protected function processTweet($tweet)
    {
        $idle = (time() - $this->lastStreamActivity);
        $this->monitor->stat('max_idle_time', $idle);
        $this->monitor->stat('idle_time', $idle);
        $this->monitor->stat('tweets', 1);
        $this->emit("tweet", [$tweet]);
    }

    /**
     * @return Monitor
     */
    public function getMonitor()
    {
        return $this->monitor;
    }

    /**
    * Returns public statuses from or in reply to a set of users. Mentions ("Hello @user!") and implicit replies
    * ("@user Hello!" created without pressing the reply button) are not matched. It is up to you to find the integer
    * IDs of each twitter user.
    * Applies to: METHOD_FILTER
    *
    * @param array $userIds Array of Twitter integer userIDs
    */
    public function setFollow(array $userIds = [])
    {
        sort($userIds);

        $this->followIds = $userIds;

        return $this;
    }

    /**
    * Returns an array of followed Twitter userIds (integers)
    *
    * @return array
    */
    public function getFollow()
    {
        return $this->followIds;
    }

    /**
    * Specifies keywords to track. Track keywords are case-insensitive logical ORs. Terms are exact-matched, ignoring
    * punctuation. Phrases, keywords with spaces, are not supported. Queries are subject to Track Limitations.
    * Applies to: METHOD_FILTER
    *
    * See: http://apiwiki.twitter.com/Streaming-API-Documentation#TrackLimiting
    *
    * @param array $trackWords
    */
    public function setTrack(array $trackWords = [])
    {
        sort($trackWords); // Non-optimal, but necessary

        $this->trackWords = $trackWords;

        return $this;
    }

    /**
    * @return array
    */
    public function getTrack()
    {
        return $this->trackWords;
    }

    /**
     * @param LocationInterface $location
     */
    public function setLocation(LocationInterface $location)
    {
        $this->location = $location;

        return $this;
    }

    /**
     * @return LocationInterface
     */
    public function getLocation()
    {
        return $this->location;
    }

    /**
    * Sets the number of previous statuses to stream before transitioning to the live stream. Applies only to firehose
    * and filter + track methods. This is generally used internally and should not be needed by client applications.
    * Applies to: METHOD_FILTER, METHOD_FIREHOSE, METHOD_LINKS
    *
    * @param int $count
    */
    public function setCount($count)
    {
        $this->count = $count;

        return $this;
    }

    /**
    * Restricts tweets to the given language, given by an ISO 639-1 code (http://en.wikipedia.org/wiki/List_of_ISO_639-1_codes).
    *
    * @param string $lang
    */
    public function setLang($lang)
    {
        $this->lang = $lang;

        return $this;
    }

    /**
    * Returns the ISO 639-1 code formatted language string of the current setting. (http://en.wikipedia.org/wiki/List_of_ISO_639-1_codes).
    *
    * @param string $lang
    */
    public function getLang()
    {
        return $this->lang;
    }
}
