<?php

namespace Ukrbublik\ReactStreamingBird;

class StreamingBird
{
    /**
     * @var string
     */
    private $consumerKey;

    /**
     * @var string
     */
    private $consumerSecret;

    /**
     * @var string
     */
    private $oauthToken;

    /**
     * @var string
     */
    private $oauthSecret;

    /**
     * @param string $consumerKey
     * @param string $consumerSecret
     * @param string $oauthToken
     * @param string $oauthSecret
     */
    public function __construct($consumerKey, $consumerSecret, $oauthToken, $oauthSecret)
    {
        $this->consumerKey    = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->oauthToken     = $oauthToken;
        $this->oauthSecret    = $oauthSecret;
    }

    /**
     * @param React\EventLoop\LoopInterface  $loop
     * @param string $method
     *
     * @return StreamReader
     */
    public function createStreamReader($loop, $method)
    {
        // Let's instantiate the Oauth signature handler and the stream reader.
        $oauth = new Oauth($this->consumerKey, $this->consumerSecret, $this->oauthToken, $this->oauthSecret);

        return new StreamReader($loop, $oauth, $method);
    }
}
