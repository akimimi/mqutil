<?php
namespace Akimimi\MessageQueueUtil;

use AliyunMNS\Client;
use AliyunMNS\Queue;
use AliyunMNS\Requests\SendMessageRequest;
use AliyunMNS\Requests\CreateQueueRequest;
use AliyunMNS\Exception\MessageNotExistException;
use AliyunMNS\Exception\QueueNotExistException;
use AliyunMNS\Exception\MnsException;
use AliyunMNS\Model\QueueAttributes;

use Akimimi\MessageQueueUtil\Exception\QueueConfigInvalidException;
use Akimimi\MessageQueueUtil\Exception\QueueNameInvalidException;

class MessageQueueUtil {

  const DEFAULT_DELAY_SECONDS = 0;
  const DEFAULT_POLLING_WAIT_SECONDS = 30;
  const DEFAULT_PRIORITY = 8;
  const DEFAULT_DEQUEUE_COUNT = 3;
  const DEFAULT_RETENTION_PERIOD = 7200; // 2 hours
  const DEFAULT_VISIBILITY_TIMEOUT = 30;

  const DEFAULT_BATCH_PEEK_MESSAGE_NUMBER = 16;

  /**
   * @var Client|null
   */
  public $client = null;
  /**
   * @var Queue|null
   */
  public $queue = null;

  /**
   * @var string
   */
  public $queueName = "";

  /**
   * @var int
   */
  public $dequeueCount = self::DEFAULT_DEQUEUE_COUNT;

  /**
   * @var string|null
   */
  public $receiptHandle = null;

  /**
   * @var AliyunMnsClientConfig|null
   */
  protected $_config = null;

  /**
   * @param string $queueName
   * @param AliyunMnsClientConfig $config
   */
  public function __construct(string $queueName, AliyunMnsClientConfig $config) {
    $this->_config = new AliyunMnsClientConfig("", "", "");
    $this->setConfig($config);
    $this->client = new Client($this->_config->endpoint, 
      $this->_config->accessId, $this->_config->accessKey);
    $this->setQueueName($queueName);
  }

  /**
   * Set message queue config
   * @param AliyunMnsClientConfig $config
   * @return void
   */
  public function setConfig(AliyunMnsClientConfig $config): void {
    $this->_config = $config;
    if (!$this->_config->isValid()) {
      throw new QueueConfigInvalidException();
    }
    else {
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
   * Set queue name
   * @param string $queueName
   * @return void
   */
  public function setQueueName(string $queueName): void {
    if (!empty($queueName)) {
      $this->queueName = $queueName;
    } else {
      throw new QueueNameInvalidException();
    }
  }

  /**
   * Initialize queue reference by and saved as $queue member.
   * @param bool $force If is set true, the queue reference will be recreated.
   * @return bool
   */
  public function initializeReference(bool $force = false): bool {
    if ($this->queue == null || $force) {
      $this->queue = $this->client->getQueueRef($this->queueName);
      return $this->queue != null;
    } else {
      return false;
    }
  }

  /**
   * Create queue with multiple queue attributes.
   * If attributes are not given, the default attribute returned by
   * getDefaultQueueAttribute() method is applied.
   *
   * @param QueueAttributes|null $queueAttr
   * @return MessageResult
   */
  public function createQueue(?QueueAttributes $queueAttr = null): MessageResult {
    if ($queueAttr == null) {
      $queueAttr = $this->getDefaultQueueAttribute();
    }
    $request = new CreateQueueRequest($this->queueName, $queueAttr);
    try {
      $res = $this->client->createQueue($request);
    }
    catch (MnsException $e) {
      return MessageResult::Failed($e, "CreateQueue");
    }
    if ($res->isSucceed()) {
      return MessageResult::Success();
    } else {
      return MessageResult::Failed('Unknown error occurs.', "CreateQueue");
    }
  }

  /**
   * Delete queue with queue name.
   *
   * @return MessageResult
   */
  public function deleteQueue(): MessageResult {
    try {
      $this->client->deleteQueue($this->queueName);
    }
    catch (MnsException $e) {
      return MessageResult::Failed($e, "DeleteQueue");
    }
    return MessageResult::Success();
  }

  /**
   * Send task in JSON formatted string.
   *
   * @param string $task
   * @param string $keyId
   * @param array|null $params
   * @param $event
   * @param int|null $delay
   * @param int|null $priority
   * @return MessageResult
   */
  public function sendTaskMessage(string $task, string $keyId, ?array $params, $event = null,
                                  ?int $delay = self::DEFAULT_DELAY_SECONDS,
                                  ?int $priority = self::DEFAULT_PRIORITY): MessageResult {
    $messageBody = array(
      'task' => $task,
      'key_id' => $keyId,
      'params' => $params,
      'event'  => $event
    );
    return $this->sendTextMessage(json_encode($messageBody), $delay, $priority);
  }

  /**
   * Send text message with body string.
   *
   * @param string $body
   * @param int|null $delay
   * @param int|null $priority
   * @return MessageResult
   */
  public function sendTextMessage(string $body, ?int $delay = self::DEFAULT_DELAY_SECONDS,
                                  ?int   $priority = self::DEFAULT_PRIORITY): MessageResult {
    $request = new SendMessageRequest($body, $delay, $priority);
    try {
      $this->initializeReference();
      $res = $this->queue->sendMessage($request);
    }
    catch (MnsException $e) {
      return MessageResult::Failed($e, "SendMessage");
    }
    if ($res->isSucceed()) {
      return MessageResult::Success();
    } else {
      return MessageResult::Failed('Unknown error occurs.', "SendMessage");
    }
  }

  /**
   * Receive message in $waitSeconds.
   * If message is received successfully, message body in string type will be returned.
   * If message is received but the dequeue count is greater or equal than dequeueCount variable,
   * the message will be deleted and a null value is returned.
   * If no message is received until $waitSeconds, null value is returned.
   * If something unexpected happens, exceptions will be thrown.
   *
   * @throws QueueNotExistException if queue does not exist
   * @throws MnsException if any other exception happens
   *
   * @param int $waitSeconds
   * @return string|null
   */
  public function receiveMessage(int $waitSeconds = self::DEFAULT_POLLING_WAIT_SECONDS): ?string {
    try {
      $this->initializeReference();
      $res = $this->queue->receiveMessage($waitSeconds);
    } catch (MessageNotExistException $e) {
      return null;
    }

    if (!empty($res)) {
      $getMessageBody = $res->getMessageBody();
      $this->receiptHandle = $res->getReceiptHandle();
      $dequeCnt = $res->getDequeueCount();

      if ($dequeCnt >= $this->dequeueCount) {
        $this->deleteMessage();
        return null;
      }
      return $getMessageBody;
    } else {
      return null;
    }
  }

  /**
   * Delete message the queue just received.
   *
   * @return MessageResult
   */
  public function deleteMessage(): MessageResult {
    try {
      $this->initializeReference();
      $this->queue->deleteMessage($this->receiptHandle);
    }
    catch (MnsException $e) {
      return MessageResult::Failed($e, "DeleteMessage");
    }

    return MessageResult::Success();
  }

  /**
   * Change the visibility of just received message. The API is usually invoked
   * when the consumer processes the message but gets a failure result.
   *
   * @param int $visibleInSeconds
   * @return MessageResult
   */
  public function changeMessageVisibility(int $visibleInSeconds = 30): MessageResult {
    $receiptHandle = $this->receiptHandle;
    $this->initializeReference();
    try {
      $this->queue->changeMessageVisibility($receiptHandle, $visibleInSeconds);
    }
    catch (MnsException $e) {
      return MessageResult::Failed($e, "ChangeVisibility");
    }
    return MessageResult::Success();
  }

  /**
   * Peek a group of messages, the number of peeked messages will not over $numOfMessages.
   * If messages are received and dequeue count is greater or equal than dequeueCount value,
   * an array of messages in string type will be returned.
   * If no message is received, null value is returned.
   *
   * @throws QueueNotExistException if queue does not exist
   * @throws MnsException if any other exception happens
   *
   * @param int $numOfMessages
   * @return array|null
   */
  public function batchPeekMessages(int $numOfMessages = self::DEFAULT_BATCH_PEEK_MESSAGE_NUMBER): ?array {
    $results = array();
    $this->initializeReference();
    try {
      $batchResp = $this->queue->batchPeekMessage($numOfMessages);
      if ($batchResp->isSucceed()) {
        $messages = $batchResp->getMessages();
        foreach ($messages as $message) {
          $result = $message->getMessageBody();
          $this->receiptHandle = $message->getReceiptHandle();
          $dequeueCnt = $message->getDequeueCount();
          if ($dequeueCnt >= $this->dequeueCount) {
            $this->deleteMessage();
          } else {
            $results[] = $result;
          }
        }
      }
    }
    catch (MessageNotExistException $e) {
      return null;
    }
    return $results;
  }

  /**
   * Return default queue attributes.
   *
   * @return QueueAttributes
   */
  public function getDefaultQueueAttribute(): QueueAttributes {
    $queueAttr = new QueueAttributes();
    $queueAttr->setDelaySeconds(self::DEFAULT_DELAY_SECONDS);
    $queueAttr->setPollingWaitSeconds(self::DEFAULT_POLLING_WAIT_SECONDS);
    $queueAttr->setMessageRetentionPeriod(self::DEFAULT_RETENTION_PERIOD);
    $queueAttr->setVisibilityTimeout(self::DEFAULT_VISIBILITY_TIMEOUT);
    return $queueAttr;
  }
}

