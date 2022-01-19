<?php
namespace Akimimi\MessageQueueUtil;

use AliyunMNS\Client;
use AliyunMNS\Requests\SendMessageRequest;
use AliyunMNS\Requests\CreateQueueRequest;
use AliyunMNS\Exception\MessageNotExistException;
use AliyunMNS\Exception\MnsException;
use AliyunMNS\Model\QueueAttributes;
use AliyunMNS\Model\BatchPeekMessageResponse;

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
   * @var AliyunMNS\Client|null
   */
  public $client = null;
  /**
   * @var AliyunMNS\Queue|null
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

  public function __construct(string $queueName, AliyunMnsClientConfig $config) {
    $this->_config = new AliyunMnsClientConfig("", "", "");
    $this->setConfig($config);
    $this->client = new Client($this->_config->endpoint, 
      $this->_config->accessId, $this->_config->accessKey);
    $this->setQueueName($queueName);
  }

  public function setConfig(AliyunMnsClientConfig $config) {
    $this->_config = $config;
    if (!$this->_config->isValid()) {
      throw new QueueConfigInvalidException();
    }
    else {
      $this->client = new Client($this->_config->endpoint, 
        $this->_config->accessId, $this->_config->accessKey);
    }
  }

  public function getConfig(): AliyunMnsClientConfig {
    return $this->_config;
  }

  public function setQueueName(string $queueName): void {
    if (!empty($queueName)) {
      $this->queueName = $queueName;
    } else {
      throw new QueueNameInvalidException();
    }
  }

  public function initializeReference($force = false): bool {
    if ($this->queue == null || $force) {
      $this->queue = $this->client->getQueueRef($this->queueName);
      return $this->queue != null;
    } else {
      return false;
    }
  }

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

  public function deleteQueue(): MessageResult {
    try {
      $rt = $this->client->deleteQueue($this->queueName);
    }
    catch (MnsException $e) {
      return MessageResult::Failed($e, "DeleteQueue");
    }
    return MessageResult::Success();
  }

  public function sendTaskMessage($task, $keyId, $params, $event = null,
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

  public function receiveMessage($waitSeconds = self::DEFAULT_POLLING_WAIT_SECONDS): ?string {
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

  public function deleteMessage(): MessageResult {
    try {
      $this->initializeReference();
      $this->queue->deleteMessage($this->receiptHandle);
    }
    catch (MnsException $e) {
      return MessageResult::FormattedError($e, "DeleteMessage");
    }

    return MessageResult::Success();
  }

  public function changeMessageVisibility(): MessageResult {
    $receiptHandle = $this->receiptHandle;
    $this->initializeReference();
    try {
      $this->queue->changeMessageVisibility($receiptHandle, 30);
    }
    catch (MnsException $e) {
      return MessageResult::FormattedError($e, "ChangeVisibility");
    }
    return MessageResult::Success();
  }

  public function batchPeekMessages($numOfMessages = self::DEFAULT_BATCH_PEEK_MESSAGE_NUMBER): ?array {
    $results = array();
    $this->initializeReference();
    try {
      $batchResp = $this->queue->batchPeekMessage($numOfMessages);
      if ($batchResp->isSucceed()) {
        $messages = $batchResp->getMessages();
        foreach ($messages as $message) {
          $results[] = $message->getMessageBody();
        }
      }
    }
    catch (MnsException $e) {
      return null;
    }
    return $results;
  }

  public function getDefaultQueueAttribute(): QueueAttributes {
    $queueAttr = new QueueAttributes();
    $queueAttr->setDelaySeconds(self::DEFAULT_DELAY_SECONDS);
    $queueAttr->setPollingWaitSeconds(self::DEFAULT_POLLING_WAIT_SECONDS);
    $queueAttr->setMessageRetentionPeriod(self::DEFAULT_RETENTION_PERIOD);
    $queueAttr->setVisibilityTimeout(self::DEFAULT_VISIBILITY_TIMEOUT);
    return $queueAttr;
  }
}

