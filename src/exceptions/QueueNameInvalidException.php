<?php
namespace Akimimi\MessageQueueUtil\Exception;

class QueueNameInvalidException extends MquException {

  public function __construct($message = "", $code = 0, Throwable $previous = null) {
    $message = "Queue name is invalid.";
    $code = 5002;
    parent::__construct($message, $code, $previous);
  }
}
