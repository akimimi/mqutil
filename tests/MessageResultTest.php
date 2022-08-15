<?php
use PHPUnit\Framework\TestCase;
use Akimimi\MessageQueueUtil\MessageResult;

final class MessageResultTest extends TestCase {

  public function testSuccessMessage(): void {
    $rt = MessageResult::Success();
    $this->assertTrue($rt->rt);
    $this->assertEquals(0, $rt->errorCode());
    $this->assertEquals("Success", $rt->errorMessage());
  }

  public function testFailedMessage(): void {
    $rt = MessageResult::Failed(
      new Exception("exception message", 100), "unittest");
    $this->assertFalse($rt->rt);
    $this->assertEquals(100, $rt->errorCode());
    $this->assertEquals(
      "unittest Failed: exception message ErrorCode: 100",
      $rt->errorMessage());
  }

  public function testFormattedError(): void {
    $e = new \AliyunMNS\Exception\MnsException(100, "exception message");
    $s = MessageResult::FormattedError($e, "unittest");
    $this->assertEquals("unittest Failed: Code: 100 Message: exception message MnsErrorCode: ClientError", $s);

    $s = MessageResult::FormattedError("exception message", "unittest");
    $this->assertEquals("unittest Failed: exception message", $s);
  }
}
