<?php

use PHPUnit\Framework\TestCase;
use Akimimi\MessageQueueUtil\AliyunMnsClientConfig;
use Akimimi\MessageQueueUtil\TopicUtil;
use Akimimi\MessageQueueUtil\Exception\TopicNameInvalidException;
use Akimimi\MessageQueueUtil\Exception\TopicConfigInvalidException;
use Akimimi\MessageQueueUtil\Exception\TopicContentCheckException;
use Akimimi\MessageQueueUtil\Exception\TopicMessageParseException;

/**
 * @covers \Akimimi\MessageQueueUtil\AliyunMnsClientConfig::__construct
 * @covers \Akimimi\MessageQueueUtil\AliyunMnsClientConfig::isValid
 * @covers \Akimimi\MessageQueueUtil\MessageResult::Success
 * @covers \Akimimi\MessageQueueUtil\MessageResult::__construct
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::__construct
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::initializeReference
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::setConfig
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::getConfig
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::setTopicName
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::createTopic
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::deleteTopic
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::unsubscribeTopic
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::getDefaultTopicAttribute
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::getTopicList
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::subscribeTopic
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::publishTextMessage
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::publishTaskMessage
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::listSubscribes
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::setContentFormat
 * @covers \Akimimi\MessageQueueUtil\TopicUtil::parseMessageFromContent
 * @covers \Akimimi\MessageQueueUtil\Exception\TopicNameInvalidException::__construct
 * @covers \Akimimi\MessageQueueUtil\Exception\TopicConfigInvalidException::__construct
 * @covers \Akimimi\MessageQueueUtil\Exception\TopicMessageParseException::__construct
 */
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

  public function testParseMessageFromContent(): void {
    $util = new TopicUtil("unittest-topic", $this->config);

    $content = <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<Notification xlmns="http://mns.aliyuncs.com/doc/v1/">
    <TopicOwner>TopicOwner</TopicOwner>
    <TopicName>TopicName</TopicName>
    <Subscriber>Subscriber</Subscriber>
    <SubscriptionName>SubscriptionName</SubscriptionName>
    <MessageId>6CC4D900CA59A2CD-1-15180534A8F-20000****</MessageId>
    <Message>Message is here.</Message>
    <MessageMD5>F1E92841751D795AB325861034B5****</MessageMD5>
    <MessageTag>important</MessageTag>
    <PublishTime>1449556920975</PublishTime>
</Notification>
EOF;
    $util->setContentFormat(TopicUtil::MESSAGE_FORMAT_XML);
    $msg = $util->parseMessageFromContent($content);
    $this->assertEquals("Message is here.", $msg);

    $content = <<<EOF
{
    "TopicOwner":"TopicOwner",
    "TopicName":"TopicName",
    "Subscriber":"Subscriber",
    "SubscriptionName":"SubscriptionName",
    "MessageId":"6CC4D900CA59A2CD-1-15180534A8F-20000****",
    "Message":"Message is here.",
    "MessageMD5":"F1E92841751D795AB325861034B5****",
    "MessageTag":"important",
    "PublishTime":"1449556920975"
}
EOF;
    $util->setContentFormat(TopicUtil::MESSAGE_FORMAT_JSON);
    $msg = $util->parseMessageFromContent($content);
    $this->assertEquals("Message is here.", $msg);

    $content = "Message is here.";
    $util->setContentFormat(TopicUtil::MESSAGE_FORMAT_SIMPLIFIED);
    $msg = $util->parseMessageFromContent($content);
    $this->assertEquals("Message is here.", $msg);
  }

  public function testParseMessageFromXmlContentWithException(): void {
    $this->expectException("Akimimi\MessageQueueUtil\Exception\TopicMessageParseException");

    $util = new TopicUtil("unittest-topic", $this->config);
    $content = <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<Notification xlmns="http://mns.aliyuncs.com/doc/v1/">
EOF;
    $util->setContentFormat(TopicUtil::MESSAGE_FORMAT_XML);
    $util->parseMessageFromContent($content);
  }

  public function testParseMessageFromJsonContentWithException(): void {
    $this->expectException("Akimimi\MessageQueueUtil\Exception\TopicMessageParseException");

    $util = new TopicUtil("unittest-topic", $this->config);
    $content = "I'm not a JSON string.";
    $util->setContentFormat(TopicUtil::MESSAGE_FORMAT_JSON);
    $util->parseMessageFromContent($content);
  }
}
