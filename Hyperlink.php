<?php 

namespace UrlChecker;

/**
 * Represents a task to check a single URL
 */
class Hyperlink
{
    const REL_START_URL = 'start_url';
    const REL_REDIRECT = 'redirect';
    const REL_LINK = 'link';

    private $url;

    /** @var Hyperlink */
    private $referer;

    private $relation;

    function __construct($url, Hyperlink $referer = null, $relation) 
    {
        $this->url = $url;
        $this->referer = $referer;
        $this->relation = $relation;
    }

    public static function createStartUrl($url)
    {
        return new self($url, null, self::REL_START_URL);
    }
    
    public static function createNormalLink($url, Hyperlink $referer)
    {
        return new self($url, $referer, self::REL_LINK);
    }

    public static function createRedirect($url, Hyperlink $referer)
    {
        return new self($url, $referer, self::REL_REDIRECT);
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function getReferer()
    {
        return $this->referer;
    }
    
    public function getRefererUrl()
    {
        return $this->referer ? $this->referer->getUrl() : null;
    }
    
    public function getRelation()
    {
        return $this->relation;
    }

    public function getRedirectCount()
    {
        if ($this->relation != self::REL_REDIRECT || !$this->referer) {
            return 0;
        }

        return 1 + $this->referer->getRedirectCount();
    }
}