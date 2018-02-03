<?php 

namespace UrlChecker;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Url;

class LinkChecker
{
    protected $fetcher;
    protected $logger;

    public function __construct(Fetcher $fetcher, LoggerInterface $logger)
    {
        $this->fetcher = $fetcher;
        $this->logger = $logger;
    }

    /** @return ResponseMetadata */
    public function checkUrl($url, $preloadBody = true)
    {
        return $this->fetcher->check($url, $preloadBody);
    }
    
    public function collectUrlsFromPage($pageUrl)
    {
        list($metadata, $html) = $this->fetcher->get($pageUrl);

        if (!$metadata->isSuccessful()) {
            $this->logger->error("Failed to fetch $pageUrl: {$metadata->getErrorReason()}");
            return [];
        }

        $crawler = new Crawler($html);
        $content = $crawler->filter('#readme');

        if (!$content->count()) {
            throw new \Exception("Page at $pageUrl has no content block");
        }

        $links = $content->filter('a[href]')->reduce(function ($node) use ($pageUrl) {

            // Remove local and empty links
            $href = $node->attr('href');

            if (preg_match("/^#/u", $href) || mb_strlen($href) == 0) {
                return false;
            };

            if (!$this->isValidUrl($href, $reason)) {
                $this->fetcher->addInvalidUrl($href, $reason, $pageUrl);
                $this->logger->error("Invalid href: $href, reason: $reason");
                return false;
            }

            $scheme = parse_url($href, PHP_URL_SCHEME);
            if ($scheme && !in_array($scheme, ['http', 'https'])) {
                $this->logger->debug("Ignore non-HTTP URL: $href");
                return false;
            }

            return true;

        })->each(function ($node) use ($pageUrl) {            
            return $this->resolveUrl($pageUrl, $node->attr('href'));
        });

        $links = array_unique($links);
        return $links;
    }

    private function resolveUrl($base, $relative)
    {
        $baseUrl = Url::fromString($base);
        $relativeUrl = Url::fromString($relative);

        $resultUrl = $baseUrl->combine($relativeUrl);

        return $resultUrl->__toString();
    }
    
    private function isValidUrl($url, &$reason)
    {
        $reason = null;

        try {
            $test = Url::fromString($url);
        } catch (\InvalidArgumentException $e) {
            $reason = $e->getMessage();
            return false;
        }

        return true;
    }
}

