<?php
namespace Akimimi\MessageQueueUtil\Exception;

class TopicConfigInvalidException extends MquException {

  public function __construct($message = "", $code = 0, Throwable $previous = null) {
    $message = "Topic config is invalid.";
    $code = 6001;
    parent::__construct($message, $code, $previous);
  }
}
