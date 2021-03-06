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
    
    public function collectUrlsFromPage(Hyperlink $link)
    {
        $pageUrl = $link->getUrl();
        list($metadata, $html) = $this->fetcher->get($pageUrl);

        if (!$metadata->isSuccessful()) {
            $this->logger->error("Failed to fetch $pageUrl: {$metadata->getErrorReason()}");
            return [];
        }

        $crawler = new Crawler($html);
        $content = $this->filterPageDom($crawler, $pageUrl);
        // $content = $crawler->filter('#readme');

        if (!$content->count()) {
            $this->logger->error("Page at $pageUrl has no content block");
            return [];
        }

        $links = $content->filter('a[href]')->reduce(function ($node) use ($link) {

            // Remove local and empty links
            $href = $node->attr('href');

            if (preg_match("/^#/u", $href) || $href == '#' || mb_strlen($href) == 0) {
                return false;
            };

            if (!$this->isValidUrl($href, $reason)) {
                $this->fetcher->addInvalidUrl($href, $reason, $link);
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
            return $this->fetcher->resolveUrl($pageUrl, $node->attr('href'));
        });

        $links = array_unique($links);
        return $links;
    }

    /**
     * Removes unnecessary parts from DOM
     *
     * @return Crawler
     */
    private function filterPageDom(Crawler $crawler, $url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        // For .md pages on Github, use only content part
        if ($host == 'github.com' && preg_match('~blob~', $path)) {
            return $crawler->filter('#readme');
        }

        return $crawler;
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

