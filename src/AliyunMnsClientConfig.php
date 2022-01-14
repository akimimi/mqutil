<?php
namespace Akimimi\MessageQueueUtil;

class AliyunMnsClientConfig {
  public $accessId = "";
  public $accessKey = "";
  public $endpoint = "";

  public function __construct($endpoint, $accessId, $accessKey) {
    $this->endpoint = $endpoint;
    $this->accessId = $accessId;
    $this->accessKey = $accessKey;
  }

  public function isValid() {
    return !empty($this->accessId) && !empty($this->accessKey) 
      && !empty($this->endpoint);
  }
}
