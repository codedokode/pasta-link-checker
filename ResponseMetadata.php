<?php 

/**
 * Represents URL check result. Must be serializable.
 */
class ResponseMetadata
{
    /** 0 if failed to resolve the hostname or to connect */
    // protected $httpCode = 0;

    protected $checkSuccess = false;

    protected $errorText = null;

    public function __construct($checkSuccess, /* , $httpCode, */ $errorText = null)
    {
        assert(is_bool($checkSuccess));
        if (!$checkSuccess) {
            assert(!empty($errorText));
        }

        $this->checkSuccess = $checkSuccess;
        // $this->httpCode = $httpCode;
        $this->errorText = $errorText;
    }
    
    public function isSuccessful()
    {
        return $this->checkSuccess;
    }
    
    public function getErrorReason()
    {
        if (!$this->checkSuccess) {
            return $this->errorText;
        }

        return null;
    }
}