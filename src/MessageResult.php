<?php
namespace Akimimi\MessageQueueUtil;

class MessageResult {
  public $rt = false;
  public $stage = "";
  public $exception = null;

  public function __construct($rt, $exception = null, $stage = "") {
    $this->rt = $rt;
    $this->exception = $exception;
    $this->stage = $stage;
  }

  public function errorCode() {
    if ($this->exception instanceof Exception) {
      return $this->exception->getCode();
    } else {
      return 0;
    }
  }

  public function errorMessage() {
    if ($this->rt) {
      return "Success";
    } else {
      return self::FormattedError($this->exception, $this->stage);
    }
  }

  static public function FormattedError($e, $stage = "") {
    if ($e instanceof MnsException) {
      return "$stage Failed: ".$e;
    } elseif ($e instanceof Exception) {
      return "$stage Failed: ".$e.PHP_EOL."ErrorCode: ".$e->getCode().PHP_EOL;
    }
    else {
      return "$stage Failed: ".strval($e);
    }
  }

  static public function Success() {
    return new MessageResult(true);
  }

  static public function Failed($exception, $stage = "") {
    return new MessageResult(false, $exception, $stage);
  }
}
