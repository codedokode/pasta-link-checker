<?php 

namespace UrlChecker;

class UrlQueue
{
    // URL => true
    protected $queuedUrls = [];

    // URL => true
    protected $checkedUrls = [];

    /**
     * @return bool true if URL was added to queue
     */
    public function addIfNew($url)
    {
        if (!$this->isNew($url)) {
            return false;
        }

        $this->queuedUrls[$url] = true;
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
     * @return string 
     */
    public function pick()
    {
        if (!$this->queuedUrls) {
            throw new \RuntimeException("Queue is empty, cannot pick");
        }

        reset($this->queuedUrls);
        $url = key($this->queuedUrls);

        unset($this->queuedUrls);

        assert(!array_key_exists($url, $this->checkedUrls));
        $this->checkedUrls[$url] = true;
        return $url;
    }
    
    public function markChecked($url)
    {
        assert(array_key_exists($url, $this->queuedUrls));
        assert(!array_key_exists($url, $this->checkedUrls));
        unset($this->queuedUrls[$url]);
        $this->checkedUrls[$url] = true;
    }
}