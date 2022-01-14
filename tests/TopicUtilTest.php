<?php

use PHPUnit\Framework\TestCase;
use Akimimi\MessageQueueUtil\AliyunMnsClientConfig;
use Akimimi\MessageQueueUtil\TopicUtil;
use Akimimi\MessageQueueUtil\Exception\TopicNameInvalidException;
use Akimimi\MessageQueueUtil\Exception\TopicConfigInvalidException;
use Akimimi\MessageQueueUtil\Exception\TopicContentCheckException;

final class TopicUtilTest extends TestCase {
  public function setUp(): void {
    $c = yaml_parse_file(__DIR__.'/aliyun_mns_test_config.yaml')['aliyunmns'];
    $this->config = new AliyunMnsClientConfig($c['endpoint'], $c['accessId'], $c['accessKey']);
    parent::setUp();
  }

  public function testConstruct(): void {
    $util = new TopicUtil("topicname", $this->config);
    $this->assertInstanceOf(TopicUtil::class, $util);
  }

  /**
   * @depends testConstruct
   */
  public function testSetAliyunMnsClientConfig(): void {
    $util = new TopicUtil("queuename", $this->config);
    $this->config->accessKey = "abcd";
    $util->setConfig($this->config);
    $c = $util->getConfig();
    $this->assertEquals("abcd", $c->accessKey);
  }

  /**
   * @depends testConstruct
   */
  public function testConstructWithEmptyTopicName(): void {
    $this->expectException(TopicNameInvalidException::class);
    new TopicUtil("", $this->config);
  }

  /**
   * @depends testConstruct
   */
  public function testConstructWithInvalidClientConfig(): void {
    $this->expectException(TopicConfigInvalidException::class);
    $this->config->accessId = "";
    new TopicUtil("topic", $this->config);
  }

  /**
   * @depends testConstruct
   */
  public function testCreateTopic(): void {
    $util = new TopicUtil("unittest-topic", $this->config);
    $rt = $util->createTopic();
    $this->assertTrue($rt->rt);
  }

  /**
   * @depends testCreateTopic
   */
  public function testGetTopicList(): void {
    $util = new TopicUtil("unittest-topic", $this->config);
    $topics = $util->getTopicList();
    $this->assertGreaterThan(0, count($topics));
  }

  /**
   * @depends testGetTopicList
   */
  public function testSubscribeTopic(): void {
    $util = new TopicUtil("unittest-topic", $this->config);
    $rt = $util->subscribeTopic("sub1", "https://www.mimixiche.com",
      "JSON", TopicUtil::DEFAULT_NOTIFY_RETRY_STRATEGY, "abc");
    $this->assertTrue($rt->rt);
  }

  /**
   * @depends testSubscribeTopic
   */
  public function testListSubscribes(): void {
    $util = new TopicUtil("unittest-topic", $this->config);
    $subscribes = $util->listSubscribes(0);
    $this->assertGreaterThanOrEqual(1, count($subscribes));
  }

  /**
   * @depends testSubscribeTopic
   */
  public function testPublishTextMessage(): void {
    $util = new TopicUtil("unittest-topic", $this->config);
    $rt = $util->publishTextMessage("plain text message");
    $this->assertTrue($rt->rt);
  }

  /**
   * @depends testSubscribeTopic
   */
  public function testPublishTaskMessage(): void {
    $util = new TopicUtil("unittest-topic", $this->config);
    $rt = $util->publishTaskMessage("something", "keyid", ['a' => 1]);
    $this->assertTrue($rt->rt);
  }

  /**
   * @depends testPublishTaskMessage
   */
  public function testUnsubscribeTopic(): void {
    $util = new TopicUtil("unittest-topic", $this->config);
    $rt = $util->unsubscribeTopic("sub1");
    $this->assertTrue($rt->rt);
  }

  /**
   * @depends testPublishTaskMessage
   */
  public function testDeleteTopic(): void {
    $util = new TopicUtil("unittest-topic", $this->config);
    $rt = $util->deleteTopic();
    $this->assertTrue($rt->rt);
  }
}