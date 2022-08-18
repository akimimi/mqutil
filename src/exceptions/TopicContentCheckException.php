<?php
namespace Akimimi\MessageQueueUtil\Exception;

class TopicContentCheckException extends MquException {

  public function __construct($message = "", $code = 0, Throwable $previous = null) {
    $message = "Topic content check failed.";
    $code = 6003;
    parent::__construct($message, $code, $previous);
  }
}