<?php

namespace Akimimi\MessageQueueUtil\Exception;

class TopicMessageParseException extends MquException {

  public function __construct($message = "", $code = 0, Throwable $previous = null) {
    $message = "Topic message parse failed.";
    $code = 6005;
    parent::__construct($message, $code, $previous);
  }
}