<?php
namespace Akimimi\MessageQueueUtil\Exception;

class TopicHeaderSignatureInvalidExecption extends MquException {

  public function __construct($message = "", $code = 0, Throwable $previous = null) {
    $message = "Topic header signature is invalid.";
    $code = 6004;
    parent::__construct($message, $code, $previous);
  }
}