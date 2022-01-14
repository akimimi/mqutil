Mimit Message Queue Utility Library 
================================================================

## Description

This library provides basic message queue and topic APIs for typical service invokes.
MessageQueueUtil provides APIs support for queues. TopicUtil provides APIs support for topic application.
Both tools in the library support AliyunMNS cloude service currently and will support more
message queue service drivers in the future.

## Installation

This library support Add require with composer CLI.
```bash
composer require akimimi/mqutil
```
Otherwise, add require to your `composer.json`.
```json
{
  "require": {
     "akimimi/mqutil": ">=1.0.0"
  }
}
```

Use Composer to install requires
```bash
composer install
```

## Usage

After installation by composer, you can declare use for MessageQueueUtil library classes.
```php
<?php
use Akimimi\MessageQueueUtil\MessageQueueUtilTest;
use Akimimi\MessageQueueUtil\AliyunMnsClientConfig;
use Akimimi\MessageQueueUtil\Exception\MquException;

$config = new AliyunMnsClientConfig("endpoint", "access_id", "access_key");
$util = new MessageQueueUtil("queue_name", $config);

# Create a queue
$util->createQueue();

# Send text messages
$util->sendTextMessage("some plain text");

# Receive messages
try {
    $messageBody = $util->receiveMessage(30);
    if ($messageBody != null) {
      // do something with your business
    }
} catch (MquException $e) {
  // do something with the exception.
}
```