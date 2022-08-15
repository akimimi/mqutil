<?php
namespace Akimimi\MessageQueueUtil;

use AliyunMNS\Exception\MnsException;
use Exception;

class MessageResult {
  public $rt = false;
  public $stage = "";
  public $exception = null;

  public function __construct($rt, $exception = null, $stage = "") {
    $this->rt = $rt;
    $this->exception = $exception;
    $this->stage = $stage;
  }

  public function errorCode(): int {
    if ($this->exception instanceof Exception) {
      return $this->exception->getCode();
    } else {
      return 0;
    }
  }

  public function errorMessage(): string {
    if ($this->rt) {
      return "Success";
    } else {
      return self::FormattedError($this->exception, $this->stage);
    }
  }

  static public function FormattedError($e, $stage = ""): string {
    if ($e instanceof MnsException) {
      return "$stage Failed: ".$e;
    } elseif ($e instanceof Exception) {
      return "$stage Failed: ".$e->getMessage()." ErrorCode: ".$e->getCode();
    }
    else {
      return "$stage Failed: ".$e;
    }
  }

  static public function Success(): MessageResult {
    return new MessageResult(true);
  }

  static public function Failed($exception, $stage = ""): MessageResult {
    return new MessageResult(false, $exception, $stage);
  }
}
