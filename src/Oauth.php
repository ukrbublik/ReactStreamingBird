<?php

namespace Ukrbublik\ReactStreamingBird;

class Oauth
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
     * @param string $url
     * @param array  $params
     *
     * @return string
     */
    public function getAuthorizationHeader($url, array $params, array $overrides = [])
    {
        $headers = $this->prepareHeaders('POST', $url, $params, $overrides);

        $headers = implode(',', array_map(function($name, $value){
            return sprintf('%s="%s"', $name, $value);
        }, array_keys($headers), $headers));

        return sprintf('OAuth realm="",%s', $headers);
    }

    /**
     * Prepares oauth headers for a given request.
     *
     * @param string $method
     * @param string $url
     * @param array  $params
     *
     * @return array
     */
    protected function prepareHeaders($method, $url, array $params, array $overrides = [])
    {
        $oauth = array_merge([
            'oauth_consumer_key'     => $this->consumerKey,
            'oauth_nonce'            => md5(uniqid(rand(), true)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => time(),
            'oauth_version'          => '1.0A',
            'oauth_token'            => $this->oauthToken,
        ], $overrides);

        foreach ($oauth as $k => $v) {
            $oauth[$k] = rawurlencode($v);
        }

        foreach ($params as $k => $v) {
            $params[$k] = rawurlencode($v);
        }

        $sigParams = array_merge($oauth, $params);
        ksort($sigParams);

        $oauth['oauth_signature'] = rawurlencode($this->generateSignature($method, $url, $sigParams));

        return $oauth;
    }

    /**
     * See https://dev.twitter.com/oauth/overview/creating-signatures
     *
     * @param  string $method
     * @param  string $url
     * @param  array  $params
     *
     * @return string
     */
    protected function generateSignature($method, $url, array $params)
    {
        $concat = implode('&', array_map(function($name, $value){
            return sprintf('%s=%s', $name, $value);
        }, array_keys($params), $params));

        $concatenatedParams = rawurlencode($concat);

        // normalize url
        $urlParts = parse_url($url);
        $scheme   = strtolower($urlParts['scheme']);
        $host     = strtolower($urlParts['host']);
        $port     = isset($urlParts['port']) ? intval($urlParts['port']) : 0;
        $retval   = strtolower($scheme) . '://' . strtolower($host);

        if (!empty($port) && (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443))) {
            $retval .= ":{$port}";
        }

        $retval .= $urlParts['path'];

        if (!empty($urlParts['query'])) {
            $retval .= "?{$urlParts['query']}";
        }

        $normalizedUrl = rawurlencode($retval);
        $signatureBaseString = "{$method}&{$normalizedUrl}&{$concatenatedParams}";
        # sign the signature string
        $key = rawurlencode($this->consumerSecret) . '&' . rawurlencode($this->oauthSecret);

        return base64_encode(hash_hmac('sha1', $signatureBaseString, $key, true));
    }
}
