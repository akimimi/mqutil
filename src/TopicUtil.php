<?php
namespace Akimimi\MessageQueueUtil;

use Akimimi\MessageQueueUtil\Exception\TopicMessageParseException;
use AliyunMNS\Client;
use AliyunMNS\Topic;
use AliyunMNS\Model\SubscriptionAttributes;
use AliyunMNS\Model\TopicAttributes;
use AliyunMNS\Requests\CreateTopicRequest;
use AliyunMNS\Requests\ListTopicRequest;
use AliyunMNS\Requests\PublishMessageRequest;
use AliyunMNS\Exception\MnsException;

use Akimimi\MessageQueueUtil\Exception\TopicConfigInvalidException;
use Akimimi\MessageQueueUtil\Exception\TopicContentCheckException;
use Akimimi\MessageQueueUtil\Exception\TopicNameInvalidException;
use Akimimi\MessageQueueUtil\Exception\TopicHeaderSignatureInvalidExecption;
use Exception;
use SimpleXMLElement;

class TopicUtil {

  const DEFAULT_MAX_MESSAGE_SIZE = 65536;

  const DEFAULT_NOTIFY_CONTENT_FORMAT = 'XML';
  const DEFAULT_NOTIFY_RETRY_STRATEGY = 'EXPONENTIAL_DECAY_RETRY';

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
   * @var string
   */
  public $contentFormat = self::CONTENT_FORMAT_XML;

  const CONTENT_FORMAT_XML = "XML";
  const CONTENT_FORMAT_JSON = "JSON";
  const CONTENT_FORMAT_SIMPLIFIED = "SIMPLIFIED";

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
   * Publish task message in JSON formatted string.
   * @param string $event
   * @param string|null $keyId
   * @param array|null $params
   * @return MessageResult
   */
  public function publishTaskMessage(string $event = '', ?string $keyId = null, ?array $params = null): MessageResult {
    $messageBody = array(
      'event' => $event,
      'key_id' => $keyId,
      'params' => $params,
    );
    return $this->publishTextMessage(json_encode($messageBody));
  }

  /**
   * Get message from HTTP request, and parse message body from XML content.
   *
   * @param bool $setHttpResponseCode Function will invoke http_response_code if this parameter is set as True.
   *                                  HTTP code 401 stands for something wrong with header signature.
   *                                  HTTP code 400 stands for something wrong with content signature.
   *                                  HTTP code 500 stands for something wrong in parsing message.
   *                                  HTTP code 200 if successfully got a message.
   * @param string|null $contentFormat Content format that HTTP listener received.
   *                                   By default, class member $contentFormat is used.
   * @return string|null Null value is returned if an exception happens,
   *                     or the message body as a string will be returned.
   * @throws Exception If XML content could not be parsed.
   */
  public function getMessage(bool $setHttpResponseCode = true, ?string $contentFormat = null): ?string {
    if ($contentFormat != null) {
      $this->setContentFormat($contentFormat);
    }
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
    try {
      $msg = $this->parseMessageFromContent($content);
      if ($setHttpResponseCode) {
        http_response_code(200);
      }
      return $msg;
    } catch (Exception $e) {
      if ($setHttpResponseCode) {
        http_response_code(500);
        return null;
      } else {
        throw $e;
      }
    }
  }

  /**
   * Check received HTTP request signature by request headers.
   *
   * @return bool
   */
  public function checkTopicSignature(): bool {
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
                                 string  $notifyContentFormat = self::DEFAULT_NOTIFY_CONTENT_FORMAT,
                                 string  $notifyStrategy = self::DEFAULT_NOTIFY_RETRY_STRATEGY,
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

  /**
   * Verify data signature with public key.
   * @param string $data
   * @param string $signature
   * @param string|null $pubKey
   * @return bool
   */
  public function verifyData(string $data, string $signature, ?string $pubKey): bool {
    $res = openssl_get_publickey($pubKey);
    $result = openssl_verify($data, base64_decode($signature), $res);
    openssl_free_key($res);

    return $result == 1;
  }

  /**
   * Set content format as XML/Json/Simplified
   * @param string $format
   * @return void
   */
  public function setContentFormat(string $format): void {
    if ($format == self::CONTENT_FORMAT_XML || $format == self::CONTENT_FORMAT_JSON
    || $format == self::CONTENT_FORMAT_SIMPLIFIED) {
      $this->contentFormat = $format;
    }
  }

  /**
   * @throws TopicContentCheckException
   */
  public function parseMessageFromContent(string $content): string {
    $msg = "";
    if ($this->contentFormat == self::CONTENT_FORMAT_XML) {
      try {
        $content = new SimpleXMLElement($content);
        $msg = $content->Message;
      } catch (Exception $e) {
        throw new TopicMessageParseException();
      }
    }
    if ($this->contentFormat == self::CONTENT_FORMAT_JSON) {
      $content = json_decode($content);
      if (!isset($content->Message)) {
        throw new TopicMessageParseException();
      } else {
        $msg = $content->Message;
      }
    }
    if ($this->contentFormat == self::CONTENT_FORMAT_SIMPLIFIED) {
      $msg = $content;
    }
    return $msg;
  }

  protected function _getByUrl($url): ?string {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);

    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
  }

  protected function _getHttpHeaders(): array {
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
