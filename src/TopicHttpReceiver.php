<?php
namespace Akimimi\MessageQueueUtil;

use Akimimi\MessageQueueUtil\Exception\TopicMessageParseException;
use Akimimi\MessageQueueUtil\Exception\TopicContentCheckException;
use Akimimi\MessageQueueUtil\Exception\TopicHeaderSignatureInvalidExecption;

class TopicHttpReceiver {

  /**
   * @var string
   */
  public $contentFormat = Topic::CONTENT_FORMAT_XML;

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
   * @throws \Exception If XML content could not be parsed.
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
    } catch (\Exception $e) {
      if ($setHttpResponseCode) {
        http_response_code(500);
        return null;
      } else {
        throw $e;
      }
    }
  }

  /**
   * Set content format as XML/Json/Simplified
   * @param string $format
   * @return void
   */
  public function setContentFormat(string $format): void {
    if ($format == Topic::CONTENT_FORMAT_XML || $format == Topic::CONTENT_FORMAT_JSON
      || $format == Topic::CONTENT_FORMAT_SIMPLIFIED) {
      $this->contentFormat = $format;
    }
  }

  /**
   * @throws TopicContentCheckException
   */
  public function parseMessageFromContent(string $content): string {
    $msg = "";
    if ($this->contentFormat == Topic::CONTENT_FORMAT_XML) {
      try {
        $content = new \SimpleXMLElement($content);
        $msg = $content->Message;
      } catch (\Exception $e) {
        throw new TopicMessageParseException();
      }
    }
    if ($this->contentFormat == Topic::CONTENT_FORMAT_JSON) {
      $content = json_decode($content);
      if (!isset($content->Message)) {
        throw new TopicMessageParseException();
      } else {
        $msg = $content->Message;
      }
    }
    if ($this->contentFormat == Topic::CONTENT_FORMAT_SIMPLIFIED) {
      $msg = $content;
    }
    return $msg;
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