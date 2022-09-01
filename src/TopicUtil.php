<?php
namespace Akimimi\MessageQueueUtil;

use AliyunMNS\Client;
use AliyunMNS\Topic;
use AliyunMNS\Model\SubscriptionAttributes;
use AliyunMNS\Model\TopicAttributes;
use AliyunMNS\Requests\CreateTopicRequest;
use AliyunMNS\Requests\ListTopicRequest;
use AliyunMNS\Requests\PublishMessageRequest;
use AliyunMNS\Exception\MnsException;

use Akimimi\MessageQueueUtil\Exception\TopicConfigInvalidException;
use Akimimi\MessageQueueUtil\Exception\TopicNameInvalidException;
use Exception;

class TopicUtil {

  const DEFAULT_MAX_MESSAGE_SIZE = 65536;

  /**
   * @var Client|null
   */
  public $client = null;

  /**
   * @var string
   */
  public $topicName = "";

  /**
   * @var Topic|null
   */
  public $topic = null;

  /**
   * @var AliyunMnsClientConfig|null
   */
  protected $_config = null;

  /**
   * @param string $topicName
   * @param AliyunMnsClientConfig $config
   */
  public function __construct(string $topicName, AliyunMnsClientConfig $config)
  {
    $this->_config = new AliyunMnsClientConfig("", "", "");
    $this->setConfig($config);
    $this->client = new Client($this->_config->endpoint,
      $this->_config->accessId, $this->_config->accessKey);
    $this->setTopicName($topicName);
  }

  /**
   * Set message queue config
   * @param AliyunMnsClientConfig $config
   * @return void
   */
  public function setConfig(AliyunMnsClientConfig $config)
  {
    $this->_config = $config;
    if (!$this->_config->isValid()) {
      throw new TopicConfigInvalidException();
    } else {
      $this->client = new Client($this->_config->endpoint,
        $this->_config->accessId, $this->_config->accessKey);
    }
  }

  /**
   * Get message queue config object.
   * @return AliyunMnsClientConfig
   */
  public function getConfig(): AliyunMnsClientConfig {
    return $this->_config;
  }

  /**
   * Set topic name.
   * @param string $topicName
   * @return void
   */
  public function setTopicName(string $topicName): void {
    if (!empty($topicName)) {
      $this->topicName = $topicName;
    } else {
      throw new TopicNameInvalidException();
    }
  }

  /**
   * Initialize topic reference by and saved as $queue member.
   * @param bool $force If is set true, the queue reference will be recreated.
   * @return bool
   */
  public function initializeReference(bool $force = false): bool {
    if ($this->topic == null || $force) {
      $this->topic = $this->client->getTopicRef($this->topicName);
      return $this->topic != null;
    } else {
      return false;
    }
  }

  /**
   * Create topic with multiple topic attributes.
   * If attributes are not given, the default attribute returned by
   * getDefaultTopicAttribute() method is applied.
   *
   * @param TopicAttributes|null $topicAttributes
   * @return MessageResult
   */
  public function createTopic(?TopicAttributes $topicAttributes = null): MessageResult {
    if ($topicAttributes == null) {
      $topicAttributes = $this->getDefaultTopicAttribute();
    }
    $request = new CreateTopicRequest($this->topicName, $topicAttributes);
    try {
      $res = $this->client->createTopic($request);
    } catch (Exception $e) {
      return MessageResult::Failed($e, "CreateTopic");
    }
    if ($res->isSucceed()) {
      return MessageResult::Success();
    } else {
      return MessageResult::Failed('Unknown error occurs.', "CreateTopic");
    }
  }

  /**
   * Delete topic with queue name.
   *
   * @return MessageResult
   */
  public function deleteTopic(): MessageResult {
    try {
      $res = $this->client->deleteTopic($this->topicName);
    } catch (Exception $e) {
      return MessageResult::Failed($e, "DeleteTopic");
    }
    if ($res->isSucceed()) {
      return MessageResult::Success();
    } else {
      return MessageResult::Failed('Unknown error occurs.', "DeleteTopic");
    }
  }

  /**
   * Publish text message to topic
   *
   * @param string $body
   * @return MessageResult
   */
  public function publishTextMessage(string $body): MessageResult {
    $request = new PublishMessageRequest($body);

    try {
      $this->initializeReference();
      $res = $this->topic->publishMessage($request);
    } catch (MnsException $e) {
      return MessageResult::Failed($e, "PublishMessage");
    }

    if ($res->isSucceed()) {
      return MessageResult::Success();
    } else {
      return MessageResult::Failed('Unknown error occurs.', "PublishMessage");
    }
  }

  /**
   * Publish event message in JSON formatted string.
   * @param string $event
   * @param string|null $keyId
   * @param array|null $params
   * @return MessageResult
   */
  public function publishEvent(string $event = '', ?string $keyId = null, ?array $params = null): MessageResult {
    $messageBody = array(
      'event' => $event,
      'key_id' => $keyId,
      'params' => $params,
    );
    return $this->publishTextMessage(json_encode($messageBody));
  }

  /**
   * Get topic list in service.
   *
   * @param int $listSize Max number of topics to return.
   * @return array|null
   */
  public function getTopicList(int $listSize = 0): ?array {
    $request = new ListTopicRequest($listSize);
    try {
      $res = $this->client->listTopic($request);
    } catch (MnsException $e) {
      return null;
    }
    if ($res->isSucceed()) {
      return $res->getTopicNames();
    } else {
      return null;
    }
  }

  /**
   * Add a subscription for topic.
   *
   * @param string $subscriptionName
   * @param string $subscriptionUrl
   * @param string $notifyContentFormat
   * @param string $notifyStrategy
   * @param string|null $filterTag
   * @return MessageResult
   */
  public function subscribeTopic(string  $subscriptionName, string $subscriptionUrl,
                                 string  $notifyContentFormat = \Akimimi\MessageQueueUtil\Topic::DEFAULT_NOTIFY_CONTENT_FORMAT,
                                 string  $notifyStrategy = \Akimimi\MessageQueueUtil\Topic::DEFAULT_NOTIFY_RETRY_STRATEGY,
                                 ?string $filterTag = null): MessageResult {
    $this->initializeReference();
    $attributes = new SubscriptionAttributes(
      $subscriptionName, $subscriptionUrl, $notifyStrategy, $notifyContentFormat,
      null, null, null, null, $filterTag);
    try {
      $res = $this->topic->subscribe($attributes);
    } catch (MnsException $e) {
      return MessageResult::Failed($e, "SubscribeTopic");
    }
    if ($res->isSucceed()) {
      return MessageResult::Success();
    } else {
      return MessageResult::Failed('Unknown error occurs.', "SubscribeTopic");
    }
  }

  /**
   * List subscribes in the topic.
   *
   * @param int $listSize Max number of subscribes to return.
   * @param string|null $prefix Subscribe name prefix
   * @param int|null $offset
   * @return array|null
   */
  public function listSubscribes(int $listSize = 0, ?string $prefix = null, ?int $offset = null): ?array
  {
    $this->initializeReference();
    try {
      $res = $this->topic->listSubscription($listSize, $prefix, $offset);
    } catch (MnsException $e) {
      return null;
    }
    if ($res->isSucceed()) {
      return $res->getSubscriptionNames();
    } else {
      return null;
    }
  }

  /**
   * Remove a subscription of the topic by subscription name.
   *
   * @param $subscriptionName
   * @return MessageResult
   */
  public function unsubscribeTopic($subscriptionName): MessageResult {
    $this->initializeReference();
    try {
      $res = $this->topic->unsubscribe($subscriptionName);
    } catch (MnsException $e) {
      return MessageResult::Failed($e, "UnsubscribeTopic");
    }
    if ($res->isSucceed()) {
      return MessageResult::Success();
    } else {
      return MessageResult::Failed('Unknown error occurs.', "UnsubscribeTopic");
    }
  }

  /**
   * Return default topic attributes.
   *
   * @return TopicAttributes
   */
  public function getDefaultTopicAttribute(): TopicAttributes {
    $attributes = new TopicAttributes;
    $attributes->setMaximumMessageSize(self::DEFAULT_MAX_MESSAGE_SIZE);
    return $attributes;
  }
}
