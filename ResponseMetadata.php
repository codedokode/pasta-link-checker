<?php 

namespace UrlChecker;

/**
 * Represents URL check result. Must be serializable.
 */
class ResponseMetadata
{
    protected $checkSuccess = false;

    protected $errorText = null;

    protected $redirected = false;

    protected $location = null;

    protected $redirectCode;

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

    public static function createWithError($errorText)
    {
        return new self(false, $errorText);
    }
    
    public static function createWithRedirect($location, $httpCode)
    {
        $self = new self(false, "Redirected to $location");
        $self->redirected = true;
        $self->location = $location;
        $self->redirectCode = $httpCode;

        return $self;
    }

    public static function createWithSuccess()
    {
        return new self(true);
    }

    public function isSuccessful()
    {
        return $this->checkSuccess;
    }

    public function isRedirected()
    {
        return $this->redirected;
    }
    
    public function getRedirectLocation()
    {
        return $this->location;
    }

    public function getRedirectCode()
    {
        return $this->redirectCode;
    }

    public function getErrorReason()
    {
        if (!$this->checkSuccess) {
            return $this->errorText;
        }

        return null;
    }
}