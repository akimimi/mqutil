<?php
use PHPUnit\Framework\TestCase;
use Akimimi\MessageQueueUtil\AliyunMnsClientConfig;

/**
 * @covers \Akimimi\MessageQueueUtil\AliyunMnsClientConfig::isValid
 * @covers \Akimimi\MessageQueueUtil\AliyunMnsClientConfig::__construct
 */
final class AliyunMnsClientConfigTest extends TestCase {

  public function testIsValid(): void {
    $config = new AliyunMnsClientConfig("", "", "");
    $this->assertTrue(!$config->isValid());

    $config->endpoint = "http://endpoint.com";
    $this->assertTrue(!$config->isValid());

    $config->accessId = "abcdefg";
    $this->assertTrue(!$config->isValid());

    $config->accessKey = "abcdefg";
    $this->assertTrue($config->isValid());
  }
}
