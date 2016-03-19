<?php 

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Url;

class Fetcher
{
    protected $client;
    protected $urlSuccess = [];
    protected $urlStatus = [];
    protected $htmlCache = []; // URL -> html

    protected $lastFetchByDomain = [];
    protected $interval = 3;

    protected $afterFetch = [];

    protected $invalidUrls = [];

    function __construct(Client $client, LoggerInterface $logger) 
    {
        $this->client = $client;    
        $this->logger = $logger;
    }

    public function reset()
    {
        $this->urlSuccess = [];
        $this->urlStatus = [];
    }

    public function loadState(array $state)
    {
        assert(is_array($state));
        assert(isset($state['urlSuccess']));
        assert(isset($state['urlStatus']));
        assert(isset($state['htmlCache']));

        $statuses = $state['urlStatus'];
        $cache = $state['htmlCache'];

        foreach ($state['urlSuccess'] as $url => $success) {
            assert(is_bool($success));
            assert(isset($statuses[$url]));
            assert(is_string($statuses[$url]));

            // How can there be NULL here? 
            // assert(array_key_exists($url, $cache));
        }

        $this->urlSuccess = $state['urlSuccess'];
        $this->urlStatus = $state['urlStatus'];
        $this->htmlCache = $state['htmlCache'];
    }
    
    public function saveState()
    {
        return [
            'urlSuccess' => $this->urlSuccess,
            'urlStatus'  => $this->urlStatus,
            'htmlCache'  => $this->htmlCache
        ];
    }
    
    public function addAfterFetchHandler(callable $handler)
    {
        $this->afterFetch[] = $handler;
    }
    
    protected function callAfterFetchHandler(array $args)
    {
        foreach ($this->afterFetch as $handler) {
            call_user_func_array($handler, $args);
        }
    }
    
    public function check($url, &$errorText)
    {
        $url = $this->normalizeUrl($url);

        if (isset($this->urlSuccess[$url])) {
            assert(isset($this->urlStatus[$url]));

            $errorText = $this->urlStatus[$url];
            return $this->urlSuccess[$url];
        }

        return !!$this->getFromNet($url, $errorText);
    }
    
    public function get($url, &$errorText)
    {
        $url = $this->normalizeUrl($url);

        if (isset($this->urlSuccess[$url])) {

            if (!$this->urlSuccess[$url]) {
                assert(isset($this->urlStatus[$url]));

                $errorText = $this->urlStatus[$url];
                return false;
            } else {
                if (!empty($this->htmlCache[$url])) {
                    assert(isset($this->htmlCache[$url]));
                    $errorText = null;
                    return $this->htmlCache[$url];
                } else {
                    $this->logger->error("URL $url not found in HTML cache, status is success though");
                }
            }
        }

        $response = $this->getFromNet($url, $errorText);
        return $response;
    }

    private function getFromNet($url, &$errorText)
    {
        $response = $this->fetchUrl($url, $errorText);
        $this->urlSuccess[$url] = !!$response;
        $this->urlStatus[$url] = $errorText;

        if (!$response) {
            return false;
        }

        $html = $response->getBody()->getContents();
        $this->htmlCache[$url] = $html;

        $this->callAfterFetchHandler([$url, $html, $errorText]);

        return $html;
    }

    private function fetchUrl($url, &$errorText)
    {
        $result = $this->fetchUrlWithoutHandlers($url, $errorText);
        return $result;
    }
    
    private function fetchUrlWithoutHandlers($url, &$errorText)
    {
        $errorText = 'No error';
        
        $domain = parse_url($url, PHP_URL_HOST);
        $this->pause($domain);

        try {
            
            $this->logger->info("GET $url");
            $response = $this->client->get($url, [
                'exceptions' => false
            ]);

        } catch (RequestException $e) {
            $this->logger->warning("GET $url failed: {$e->getMessage()}");
            $errorText = $e->getMessage();
            return false;
        }

        // Response code
        if ($response->getStatusCode() != 200) {
            $errorText = "{$response->getStatusCode()} {$response->getReasonPhrase()}";
            return false;
        }

        // Content type
        $type = $response->getHeader('Content-Type'); 
        if (!$type) {
            $errorText = "No Content-Type";
            return false;
        }

        if (!preg_match("#^text/html#i", $type)) {
            $errorText = "Content-Type invalid: $type";
            return false;
        }

        return $response;
    }

    public function getFailedUrlList()
    {
        $result = [];

        foreach ($this->urlSuccess as $url => $success) {
            if (!$success) {
                $result[$url] = $this->urlStatus[$url];
            }
        }

        $result = $result + $this->invalidUrls;

        return $result;
    }

    public function addInvalidUrl($href, $reason, $usedBy = [])
    {
        $this->invalidUrls[$href] = $reason;
    }

    protected function pause($domain)
    {
        $lastFetch = $this->getLastFetch($domain);
        $passed = microtime(true) - $lastFetch;

        if ($passed < $this->interval) {
            $mustSleep = $this->interval - $passed;
            $this->logger->debug("Sleeping $mustSleep sec");
            usleep(ceil($mustSleep * 1e6));
        }

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
