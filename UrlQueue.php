<?php 

namespace UrlChecker;

class UrlQueue
{
    // URL => Hyperlink
    protected $queuedUrls = [];

    // URL => Hyperlink
    protected $checkedUrls = [];

    /**
     * @return bool true if URL was added to queue
     */
    public function addIfNew(Hyperlink $link)
    {
        $url = $link->getUrl();

        if (!$this->isNew($url)) {
            return false;
        }

        $this->queuedUrls[$url] = $link;
        return true;
    }
    
    public function isNew($url)
    {
        return !array_key_exists($url, $this->queuedUrls) && 
            !array_key_exists($url, $this->checkedUrls);
    }

    public function isChecked($url)
    {
        return array_key_exists($url, $this->checkedUrls);
    }
    
    public function getQueuedCount()
    {
        return count($this->queuedUrls);
    }

    public function getCheckedCount()
    {
        return count($this->checkedUrls);
    }

    /**
     * @return array [url => true]
     */
    public function getQueuedUrls()
    {
        return $this->queuedUrls;
    }
    
    /**
     * @return array [url => true]
     */
    public function getCheckedUrls()
    {
        return $this->checkedUrls;
    }

    /**
     * Picks URL and marks it as checked. Throws an exception if 
     * queue is empty.
     *
     * @return Hyperlink 
     */
    public function pick()
    {
        if (!$this->queuedUrls) {
            throw new \RuntimeException("Queue is empty, cannot pick");
        }

        reset($this->queuedUrls);
        $link = reset($this->queuedUrls);
        $key = key($this->queuedUrls);
        $url = $link->getUrl();

        unset($this->queuedUrls[$key]);

        assert(!array_key_exists($url, $this->checkedUrls));
        $this->checkedUrls[$url] = $link;
        return $link;
    }
    
    public function markChecked(Hyperlink $link)
    {
        $url = $link->getUrl();

        assert(array_key_exists($url, $this->queuedUrls));
        assert(!array_key_exists($url, $this->checkedUrls));
        unset($this->queuedUrls[$url]);
        $this->checkedUrls[$url] = $link;
    }
}