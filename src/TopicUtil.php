<?php
namespace Akimimi\MessageQueueUtil;

use AliyunMNS\Client;
use AliyunMNS\Model\SubscriptionAttributes;
use AliyunMNS\Model\TopicAttributes;
use AliyunMNS\Requests\CreateTopicRequest;
use AliyunMNS\Requests\ListTopicRequest;
use AliyunMNS\Requests\PublishMessageRequest;

use Akimimi\MessageQueueUtil\Exception\TopicConfigInvalidException;
use Akimimi\MessageQueueUtil\Exception\TopicContentCheckException;
use Akimimi\MessageQueueUtil\Exception\TopicNameInvalidException;
use Akimimi\MessageQueueUtil\Exception\TopicHeaderSignatureInvalidExecption;

class TopicUtil
{

  const DEFAULT_MAX_MESSAGE_SIZE = 65536;

  const DEFAULT_NOTIFY_CONTENT_FORMAT = 'XML';
  const DEFAULT_NOTIFY_RETRY_STRATEGY = 'EXPONENTIAL_DECAY_RETRY';

  /**
   * @var AliyunMNS\Client|null
   */
  public $client = null;

  /**
   * @var string
   */
  public $topicName = "";

  /**
   * @var AliyunMNS\Topic
   */
  public $topic = null;

  /**
   * @var AliyunMnsClientConfig|null
   */
  protected $_config = null;

  public function __construct(string $topicName, AliyunMnsClientConfig $config)
  {
    $this->_config = new AliyunMnsClientConfig("", "", "");
    $this->setConfig($config);
    $this->client = new Client($this->_config->endpoint,
      $this->_config->accessId, $this->_config->accessKey);
    $this->setTopicName($topicName);
  }

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

  public function getConfig(): AliyunMnsClientConfig
  {
    return $this->_config;
  }

  public function setTopicName(string $topicName): void
  {
    if (!empty($topicName)) {
      $this->topicName = $topicName;
    } else {
      throw new TopicNameInvalidException();
    }
  }

  public function initializeReference($force = false): bool
  {
    if ($this->topic == null || $force) {
      $this->topic = $this->client->getTopicRef($this->topicName);
      return $this->topic != null;
    } else {
      return false;
    }
  }

  public function createTopic(?TopicAttributes $topicAttributes = null): MessageResult
  {
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

  public function deleteTopic(): MessageResult
  {
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

  public function publishTextMessage(string $body): MessageResult
  {
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

  public function publishTaskMessage(string $event = '', $keyId = null, $params = null): MessageResult
  {
    $messageBody = array(
      'event' => $event,
      'key_id' => $keyId,
      'params' => $params,
    );
    return $this->publishTextMessage(json_encode($messageBody));
  }

  /**
   * @param bool $setHttpResponseCode Function will invoke http_response_code if this parameter is set as True.
   *                                  HTTP code 401 stands for something wrong with header signature.
   *                                  HTTP code 400 stands for something wrong with content signature.
   *                                  HTTP code 200 if successfully got a message.
   * @return string|null Null value is returned if an exception happens,
   *                     or the message body as a string will be returned.
   */
  public function getMessage(bool $setHttpResponseCode = true): ?string
  {
    if (!$this->checkTopicSignature()) {
      if ($setHttpResponseCode) {
        http_response_code(401);
      }
      throw new TopicHeaderSignatureInvalidExecption();
    }
    $content = file_get_contents("php://input");

    if (!empty($contentMd5) && $contentMd5 != base64_encode(md5($content))) {
      if ($setHttpResponseCode) {
        http_response_code(400);
      }
      throw new TopicContentCheckException();
    }
    $content = new SimpleXMLElement($content);
    $msg = $content->Message;
    if ($setHttpResponseCode) {
      http_response_code(200);
    }
    return $msg;
  }

  public function checkTopicSignature(): bool
  {
    $tmpHeaders = array();
    $headers = $this->_getHttpHeaders();
    foreach ($headers as $key => $value) {
      if (0 === strpos($key, 'x-mns-')) {
        $tmpHeaders[$key] = $value;
      }
    }
    ksort($tmpHeaders);
    $canonicalizedMNSHeaders = implode("\n", array_map(function ($v, $k) {
      return $k . ":" . $v;
    }, $tmpHeaders, array_keys($tmpHeaders)));
    $method = $_SERVER['REQUEST_METHOD'];
    $canonicalizedResource = $_SERVER['REQUEST_URI'];
    $contentMd5 = '';
    if (array_key_exists('Content-MD5', $headers)) {
      $contentMd5 = $headers['Content-MD5'];
    } else if (array_key_exists('Content-md5', $headers)) {
      $contentMd5 = $headers['Content-md5'];
    } else if (array_key_exists('Content-Md5', $headers)) {
      $contentMd5 = $headers['Content-Md5'];
    } else if (array_key_exists('content-md5', $headers)) {
      $contentMd5 = $headers['content-md5'];
    }
    $contentType = '';
    if (array_key_exists('Content-Type', $headers)) {
      $contentType = $headers['Content-Type'];
    } else if
    (array_key_exists('content-type', $headers)) {
      $contentType = $headers['content-type'];
    }
    if (!isset($headers['date'])) {
      return false;
    }
    $date = $headers['date'];
    $stringToSign = strtoupper($method) . "\n" . $contentMd5 . "\n" . $contentType . "\n" . $date . "\n" . $canonicalizedMNSHeaders . "\n" . $canonicalizedResource;
    $publicKeyURL = base64_decode($headers['x-mns-signing-cert-url']);
    $publicKey = $this->_getByUrl($publicKeyURL);
    $signature = $headers['authorization'];

    return $this->verifyData($stringToSign, $signature, $publicKey);
  }

  public function getTopicList(int $listSize = 0): ?array
  {
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

  public function subscribeTopic(string  $subscriptionName, string $subscriptionUrl,
                                 string  $notifyContentFormat = self::DEFAULT_NOTIFY_CONTENT_FORMAT,
                                 string  $notifyStrategy = self::DEFAULT_NOTIFY_RETRY_STRATEGY,
                                 ?string $filterTag = null): MessageResult
  {
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


  public function unsubscribeTopic($subscriptionName): MessageResult
  {
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


  public function getDefaultTopicAttribute(): TopicAttributes
  {
    $attributes = new TopicAttributes;
    $attributes->setMaximumMessageSize(self::DEFAULT_MAX_MESSAGE_SIZE);
    return $attributes;
  }

  public function verifyData($data, $signature, $pubKey): bool
  {
    $res = openssl_get_publickey($pubKey);
    $result = openssl_verify($data, base64_decode($signature), $res);
    openssl_free_key($res);

    return $result == 1;
  }

  protected function _getByUrl($url): ?string
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);

    $output = curl_exec($ch);

    curl_close($ch);

    return $output;
  }

  protected function _getHttpHeaders(): array
  {
    $headers = array();
    foreach ($_SERVER as $name => $value) {

      if (substr($name, 0, 5) == 'HTTP_') {
        $key = str_replace(' ', '-', strtolower(str_replace('_', ' ', substr($name, 5))));
        $headers[$key] = $value;
      }
    }
    return $headers;
  }
}
