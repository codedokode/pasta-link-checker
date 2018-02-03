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
    // protected $requests = [];

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
    public function check($url, $preloadBody = true)
    {
        $url = $this->normalizeUrl($url);

        $key = $this->getKeyForMetadata($url);
        $cached = $this->metadataCache->fetch($key);
        if (false !== $cached) {
            assert($cached instanceof ResponseMetadata);

            // $this->requests[$url] = $cached;
            return $cached;
        }

        // try HEAD first
        $shouldMakeGet = true;

        if (!$preloadBody) {
            list($metadata, $cannotUseHead) = $this->makeHeadRequest($url);
            $shouldMakeGet = $cannotUseHead;
        }

        if ($shouldMakeGet) {
            list($metadata, $html) = $this->getFromNet($url);
        }

        $this->metadataCache->save($key, $metadata, $this->cacheLifetimeSeconds);
        // $this->requests[$url] = $metadata;

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
            // $this->requests[$url] = $cachedMeta;

            return [$cachedMeta, $cachedBody];
        }

        list($metadata, $html) = $this->getFromNet($url);
        $this->metadataCache->save($metaKey, $metadata, $this->cacheLifetimeSeconds);
        $this->metadataCache->save($bodyKey, $html, $this->cacheLifetimeSeconds);
        // $this->requests[$url] = $metadata;

        return [$metadata, $html];
    }

    /**
     * @return [ResponseMetadata $meta, string $html]
     */
    private function getFromNet($url /* , &$errorText */)
    {
        list($response, $errorText) = $this->fetchUrl($url, false);
        $metadata = $this->parseResponse($response, $errorText, $url);

        if (!$response) {
            return [$metadata, null];
        }

        $html = $response->getBody()->getContents();
        // $this->callAfterFetchHandler([$url, $html, $errorText]);

        return [$metadata, $html];
    }

    private function parseResponse($response = null, $errorText, $pageUrl)
    {
        if ($response && 
            $response->getStatusCode() >= 300 && 
            $response->getStatusCode() < 400 && 
            $response->hasHeader('Location')
        ) {
            $relLocation = $response->getHeader('Location');
            $location = $this->resolveUrl($pageUrl, $relLocation);

            $metadata = ResponseMetadata::createWithRedirect(
                $location,
                $response->getStatusCode()
            );
        } elseif ($errorText) {
            $metadata = ResponseMetadata::createWithError($errorText);
        } else {
            $metadata = ResponseMetadata::createWithSuccess();
        }

        return $metadata;
    }

    /**
     * @return [ResponseMetadata $meta, bool $cannotUseHead]
     */
    private function makeHeadRequest($url)
    {
        list($response, $errorText) = $this->fetchUrl($url, true);
        $cannotUseHead = $response && $response->getStatusCode() == 405;
        $metadata = $this->parseResponse($response, $errorText, $url);

        // $metadata = new ResponseMetadata(!$errorText, $errorText);
        return [$metadata, $cannotUseHead];
    }

    /**
     * @return [GuzzleResponse $resp = null, string $errorText = null]
     */    
    private function fetchUrl($url, $useHead)
    {
        $errorText = 'No error';
        
        // $domain = $this->getDomainForPause($url);
        $this->pause($url);

        try {
            
            if ($useHead) {
                $this->logger->info("HEAD $url");
                $response = $this->client->head($url, [
                    'exceptions'        => false,
                    'allow_redirects'   =>  false
                ]);
            } else {
                $this->logger->info("GET $url");
                $response = $this->client->get($url, [
                    'exceptions'        =>  false,
                    'allow_redirects'   =>  false
                ]);
            }

        } catch (RequestException $e) {
            $method = $useHead ? 'HEAD' : 'GET';
            $this->logger->warning("$method $url failed: {$e->getMessage()}");
            $errorText = $e->getMessage();
            return [null, $errorText];
        }

        // Response code
        if ($response->getStatusCode() != 200) {
            $errorText = "{$response->getStatusCode()} {$response->getReasonPhrase()}";
            return [$response, $errorText];
        }

        // Content type
        $type = $response->getHeader('Content-Type'); 
        if (!$type) {
            $errorText = "No Content-Type";
            return [$response, $errorText];
        }

        if (!preg_match("#^text/html#i", $type)) {
            $errorText = "Content-Type invalid: $type";
            return [$response, $errorText];
        }

        return [$response, null];
    }

    // public function getFailedUrlList()
    // {
    //     $result = [];

    //     foreach ($this->requests as $url => $metadata) {
    //         if (!$metadata->isSuccessful()) {
    //             $result[$url] = $metadata->getErrorReason();
    //         }
    //     }

    //     $result = $result + $this->invalidUrls;

    //     return $result;
    // }

    public function getInvalidUrls()
    {
        return $this->invalidUrls;
    }

    public function addInvalidUrl($href, $reason, Hyperlink $referer = null)
    {
        $link = Hyperlink::createNormalLink($href, $referer);
        $metadata = ResponseMetadata::createWithError($reason);

        $this->invalidUrls[] = [
            'link'      =>  $link,
            'metadata'  =>  $metadata
        ];
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

    public function resolveUrl($base, $relative)
    {
        $baseUrl = Url::fromString($base);
        $relativeUrl = Url::fromString($relative);

        $resultUrl = $baseUrl->combine($relativeUrl);

        return $resultUrl->__toString();
    }    
}
