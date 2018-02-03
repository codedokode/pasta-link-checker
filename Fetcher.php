<?php 

namespace UrlChecker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Url;
use Doctrine\Common\Cache\Cache;

class Fetcher
{
    /** @var Cache */
    protected $responseCache;

    /** @var Cache */
    protected $metadataCache;

    protected $cacheLifetimeSeconds;

    protected $client;

    protected $lastFetchByDomain = [];
    protected $interval = 3;

    // protected $afterFetch = [];

    protected $invalidUrls = [];

    // URL => metadata
    protected $requests = [];

    protected $logger;

    /**
     * @param Cache $responseCache Stores complete HTML response
     * @param Cache $statusCache   Stores only response metadata (status, headers)
     */
    function __construct(
        Client $client, 
        Cache $responseCache, 
        Cache $metadataCache, 
        $cacheLifetimeSeconds, 
        LoggerInterface $logger) 
    {
        $this->client = $client;
        $this->responseCache = $responseCache;
        $this->metadataCache = $metadataCache;
        $this->cacheLifetimeSeconds = $cacheLifetimeSeconds;
        $this->logger = $logger;
    }

    private function getKeyForMetadata($url)
    {
        return "meta::$url";
    }
    
    private function getKeyForResponseBody($url)
    {
        return "body::$url";
    }

    /**
     * @return ResponseMetadata 
     */
    public function check($url)
    {
        $url = $this->normalizeUrl($url);

        $key = $this->getKeyForMetadata($url);
        $cached = $this->metadataCache->fetch($key);
        if (false !== $cached) {
            assert($cached instanceof ResponseMetadata);

            $this->requests[$url] = $cached;
            return $cached;
        }

        // TODO: try HEAD first
        list($metadata, $html) = $this->getFromNet($url);

        $this->metadataCache->save($key, $metadata, $this->cacheLifetimeSeconds);
        $this->requests[$url] = $cached;

        return $metadata;
    }
    
    /**
     * @return array [ResponseMetadata $metadata, string $html]
     */
    public function get($url /* , &$errorText */)
    {
        $url = $this->normalizeUrl($url);
        $metaKey = $this->getKeyForMetadata($url);
        $bodyKey = $this->getKeyForResponseBody($url);
        $cachedMeta = $this->metadataCache->fetch($metaKey);
        $cachedBody = $this->metadataCache->fetch($bodyKey);

        if ($cachedMeta !== false && $cachedBody !== false) {
            assert($cachedMeta instanceof ResponseMetadata);
            $this->requests[$url] = $cachedMeta;

            return [$cachedMeta, $cachedBody];
        }

        list($metadata, $html) = $this->getFromNet($url);
        $this->metadataCache->save($metaKey, $metadata, $this->cacheLifetimeSeconds);
        $this->metadataCache->save($bodyKey, $html, $this->cacheLifetimeSeconds);
        $this->requests[$url] = $metadata;

        return [$metadata, $html];
    }

    /**
     * @return [ResponseMetadata $meta, string $html]
     */
    private function getFromNet($url /* , &$errorText */)
    {
        list($response, $errorText) = $this->fetchUrlWithoutHandlers($url);
        $metadata = new ResponseMetadata(!!$response, $errorText);

        if (!$response) {
            return [$metadata, null];
        }

        $html = $response->getBody()->getContents();
        // $this->callAfterFetchHandler([$url, $html, $errorText]);

        return [$metadata, $html];
    }

    /**
     * @return [GuzzleResponse $resp = null, string $errorText = null]
     */    
    private function fetchUrlWithoutHandlers($url /* , &$errorText */)
    {
        $errorText = 'No error';
        
        // $domain = $this->getDomainForPause($url);
        $this->pause($url);

        try {
            
            $this->logger->info("GET $url");
            $response = $this->client->get($url, [
                'exceptions' => false
            ]);

        } catch (RequestException $e) {
            $this->logger->warning("GET $url failed: {$e->getMessage()}");
            $errorText = $e->getMessage();
            return [null, $errorText];
        }

        // Response code
        if ($response->getStatusCode() != 200) {
            $errorText = "{$response->getStatusCode()} {$response->getReasonPhrase()}";
            return [null, $errorText];
        }

        // Content type
        $type = $response->getHeader('Content-Type'); 
        if (!$type) {
            $errorText = "No Content-Type";
            return [null, $errorText];
        }

        if (!preg_match("#^text/html#i", $type)) {
            $errorText = "Content-Type invalid: $type";
            return [null, $errorText];
        }

        return [$response, null];
    }

    public function getFailedUrlList()
    {
        $result = [];

        foreach ($this->requests as $url => $metadata) {
            if (!$metadata->isSuccessful()) {
                $result[$url] = $metadata->getErrorReason();
            }
        }

        $result = $result + $this->invalidUrls;

        return $result;
    }

    public function addInvalidUrl($href, $reason, $usedBy = [])
    {
        $this->invalidUrls[$href] = $reason;
    }

    public function getExpectedFetchTime($url)
    {
        $url = $this->normalizeUrl($url);

        // If URL is in cache, no time required
        $bodyKey = $this->getKeyForResponseBody($url);
        if ($this->responseCache->contains($bodyKey)) {
            return 0;
        }

        return $this->getWaitTime($url);
    }

    /**
     * Returns how much we must wait before trying to make 
     * a request to a specified URL. 0 means we don't have to wait.
     *
     * @return float 
     */
    public function getWaitTime($url)
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme == 'file') {
            return 0;
        }

        $domain = $this->getDomainForPause($url);
        $lastFetch = $this->getLastFetch($domain);

        $passed = microtime(true) - $lastFetch;
        if ($passed < $this->interval) {
            $mustSleep = $this->interval - $passed;
            return $mustSleep;
        }

        return 0;
    }

    protected function getDomainForPause($url)
    {
        $domain = parse_url($url, PHP_URL_HOST);
        return $domain;
    }

    protected function pause($url)
    {
        $mustSleep = $this->getWaitTime($url);
        $domain = $this->getDomainForPause($url);

        if ($mustSleep > 0) {
            $this->logger->debug("Sleeping $mustSleep sec (for $domain)");
            usleep(ceil($mustSleep * 1e6));
        }

        // $lastFetch = $this->getLastFetch($domain);
        // $passed = microtime(true) - $lastFetch;

        // if ($passed < $this->interval) {
        //     $mustSleep = $this->interval - $passed;
        //     $this->logger->debug("Sleeping $mustSleep sec (for $domain)");
        //     usleep(ceil($mustSleep * 1e6));
        // }

        $this->lastFetchByDomain[$domain] = microtime(true);
    }
    
    private function getLastFetch($domain)
    {
        return isset($this->lastFetchByDomain[$domain]) ? 
            $this->lastFetchByDomain[$domain] : 0;
    }

    private function normalizeUrl($url)
    {
        $urlObject = Url::fromString($url);

        // Remove fragment
        if ($urlObject->getFragment() !== null) {
            $urlObject->setFragment(null);
        }

        $result = $urlObject->__toString();
        return $result;

    }       
}
