<?php
namespace Akimimi\MessageQueueUtil\Exception;

class TopicNameInvalidException extends MquException {
  public function __construct($message = "", $code = 0, Throwable $previous = null) {
    $message = "Topic name is invalid.";
    $code = 6002;
    parent::__construct($message, $code, $previous);
  }
}
