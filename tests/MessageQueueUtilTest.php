<?php
use PHPUnit\Framework\TestCase;
use Akimimi\MessageQueueUtil\AliyunMnsClientConfig;
use Akimimi\MessageQueueUtil\MessageQueueUtil;
use Akimimi\MessageQueueUtil\Exception\QueueConfigInvalidException;
use Akimimi\MessageQueueUtil\Exception\QueueNameInvalidException;

/**
 * @covers \Akimimi\MessageQueueUtil\MessageResult::Success
 * @covers \Akimimi\MessageQueueUtil\MessageResult::__construct
 * @covers \Akimimi\MessageQueueUtil\AliyunMnsClientConfig::__construct
 * @covers \Akimimi\MessageQueueUtil\AliyunMnsClientConfig::isValid
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::__construct
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::initializeReference
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::setConfig
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::getConfig
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::setQueueName
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::createQueue
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::getDefaultQueueAttribute
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::sendTextMessage
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::sendTaskMessage
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::receiveMessage
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::changeMessageVisibility
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::deleteMessage
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::batchPeekMessages
 * @covers \Akimimi\MessageQueueUtil\MessageQueueUtil::deleteQueue
 */
final class MessageQueueUtilTest extends TestCase {

  public function setUp(): void {
    $c = yaml_parse_file(__DIR__.'/aliyun_mns_test_config.yaml')['aliyunmns'];
    $this->config = new AliyunMnsClientConfig($c['endpoint'], $c['accessId'], $c['accessKey']);
    parent::setUp();
  }

  public function testConstruct(): void {
    $util = new MessageQueueUtil("queuename", $this->config);
    $this->assertInstanceOf(MessageQueueUtil::class, $util);
  }

  /**
   * @depends testConstruct
   */
  public function testSetAliyunMnsClientConfig(): void {
    $util = new MessageQueueUtil("queuename", $this->config);
    $this->config->accessKey = "abcd";
    $util->setConfig($this->config);
    $c = $util->getConfig();
    $this->assertEquals("abcd", $c->accessKey);
  }

  /**
   * @depends testConstruct
   */
  public function testConstructWithEmptyQueueName(): void {
    $this->expectException(QueueNameInvalidException::class);
    new MessageQueueUtil("", $this->config);
  }

  /**
   * @depends testConstruct
   */
  public function testConstructWithInvalidClientConfig(): void {
    $this->expectException(QueueConfigInvalidException::class);
    $this->config->accessId = "";
    $util = new MessageQueueUtil("queuename", $this->config);
  }
  
  /**
   * @depends testConstruct
   */
  public function testCreateQueue(): void {
    $util = new MessageQueueUtil("unittest-queue", $this->config);
    $rt = $util->createQueue();
    $this->assertTrue($rt->rt);
  }

  /**
   * @depends testCreateQueue
   */
  public function testSendTextToQueue(): void {
    $util = new MessageQueueUtil("unittest-queue", $this->config);
    $rt = $util->sendTextMessage("plain text message");
    $this->assertTrue($rt->rt);
    $util->sendTextMessage("plain text message");
    $util->sendTextMessage("plain text message");
    $util->sendTextMessage("plain text message");
    $util->sendTextMessage("plain text message");
  }

  /**
   * @depends testSendTextToQueue
   */
  public function testReceiveFromQueue(): ?string {
    $util = new MessageQueueUtil("unittest-queue", $this->config);
    $body = $util->receiveMessage();
    $this->assertEquals("plain text message", $body);
    return $util->receiptHandle;
  }

  /**
   * @depends testReceiveFromQueue
   */
  public function testChangeVisibility(?string $receiptHandle): ?string {
    $this->assertIsString($receiptHandle);
    $util = new MessageQueueUtil("unittest-queue", $this->config);
    $util->receiptHandle = $receiptHandle;
    $rt = $util->changeMessageVisibility();
    $this->assertTrue($rt->rt);
    return $receiptHandle;
  }

  /**
   * @depends testChangeVisibility
   */
  public function testDeleteMessage(?string $receiptHandle): void {
    $this->assertIsString($receiptHandle);
    $util = new MessageQueueUtil("unittest-queue", $this->config);
    $util->receiptHandle = $receiptHandle;
    $rt = $util->deleteMessage();
    $this->assertTrue($rt->rt);
  }

  /**
   * @depends testDeleteMessage
   */
  public function testBatchPeekMessages(): void {
    $util = new MessageQueueUtil("unittest-queue", $this->config);
    $messages = $util->batchPeekMessages(5);
    $this->assertGreaterThanOrEqual(1, count($messages));
  }

  /**
   * @depends testBatchPeekMessages
   */
  public function testSendTaskToQueue(): void {
    $util = new MessageQueueUtil("unittest-queue", $this->config);
    $rt = $util->sendTaskMessage("task", "keyid", ['a' => 1]);
    $this->assertTrue($rt->rt);
  }

  /**
   * @depends testBatchPeekMessages
   */
  public function testDeleteQueue(): void {
    $util = new MessageQueueUtil("unittest-queue", $this->config);
    $rt = $util->deleteQueue();
    $this->assertTrue($rt->rt);
  }
}

