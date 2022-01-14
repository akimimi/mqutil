<?php
namespace Akimimi\MessageQueueUtil\Exception;

class MquException extends \RuntimeException {

  public function __toString() {
    return  "[MessageQueueUtil]code: "
      .$this->getCode." Message: ".$this->getMessage();
  }
}
