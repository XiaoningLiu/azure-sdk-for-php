<?php

/**
 * LICENSE: Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * 
 * PHP version 5
 *
 * @category  Microsoft
 * @package   WindowsAzure\Services\Queue
 * @author    Azure PHP SDK <azurephpsdk@microsoft.com>
 * @copyright 2012 Microsoft Corporation
 * @license   http://www.apache.org/licenses/LICENSE-2.0  Apache License 2.0
 * @link      http://pear.php.net/package/azure-sdk-for-php
 */

namespace WindowsAzure\Services\ServiceBus;
use WindowsAzure\Core\Atom\Content;
use WindowsAzure\Core\Atom\Entry;
use WindowsAzure\Core\Atom\Feed;
use WindowsAzure\Core\Http\IHttpClient;
use WindowsAzure\Core\Http\HttpCallContext;
use WindowsAzure\Core\Http\Url;
use WindowsAzure\Core\Serialization\XmlSerializer;
use WindowsAzure\Core\WindowsAzureUtilities;
use WindowsAzure\Services\Core\Models\GetServicePropertiesResult;
use WindowsAzure\Services\Core\Models\ServiceProperties;
use WindowsAzure\Services\Core\ServiceRestProxy;
use WindowsAzure\Services\ServiceBus\IServiceBus;
use WindowsAzure\Services\ServiceBus\Models\BrokeredMessage;
use WindowsAzure\Services\ServiceBus\Models\BrokerProperties;
use WindowsAzure\Services\ServiceBus\Models\CreateQueueResult;
use WindowsAzure\Services\ServiceBus\Models\CreateRuleResult;
use WindowsAzure\Services\ServiceBus\Models\CreateTopicResult;
use WindowsAzure\Services\ServiceBus\Models\CreateSubscriptionResult;
use WindowsAzure\Services\ServiceBus\Models\QueueDescription;
use WindowsAzure\Services\ServiceBus\Models\QueueInfo;
use WindowsAzure\Services\ServiceBus\Models\RuleDescription;
use WindowsAzure\Services\ServiceBus\Models\RuleInfo;
use WindowsAzure\Services\ServiceBus\Models\SubscriptionDescription;
use WindowsAzure\Services\ServiceBus\Models\SubscriptionInfo;
use WindowsAzure\Services\ServiceBus\Models\TopicDescription;
use WindowsAzure\Services\ServiceBus\Models\TopicInfo;
use WindowsAzure\Resources;
use WindowsAzure\Utilities;
use WindowsAzure\Validate;

/**
 * This class constructs HTTP requests and receive HTTP responses for service bus.
 *
 * @category  Microsoft
 * @package   WindowsAzure\Services\ServiceBus
 * @author    Azure PHP SDK <azurephpsdk@microsoft.com>
 * @copyright 2012 Microsoft Corporation
 * @license   http://www.apache.org/licenses/LICENSE-2.0  Apache License 2.0
 * @version   Release: @package_version@
 * @link      http://pear.php.net/package/azure-sdk-for-php
 */

class ServiceBusRestProxy extends ServiceRestProxy implements IServiceBus
{
    /**
     * Creates a ServiceBusRestProxy with specified parameter. 
     * 
     * @param IHttpClient $channel        The channel to communicate. 
     * @param string      $uri            The URI of service bus service.
     * @param ISerializer $dataSerializer The serializer of the service bus.
     *
     * @return none
     */
    public function __construct($channel, $uri, $dataSerializer)
    {
        parent::__construct($channel, $uri, '', $dataSerializer);
    }
    
    /**
     * Sends a brokered message. 
     * 
     * @param type $path            The path to send message. 
     * @param type $brokeredMessage The brokered message. 
     *
     * @return none
     */
    public function sendMessage($path, $brokeredMessage)
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_POST);
        $httpCallContext->addStatusCode(Resources::STATUS_OK);
        $contentType = $brokeredMessage->getContentType();

        if (!is_null($contentType))
        {
            $httpCallContext->addHeader(
                Resources::CONTENT_TYPE,
                $brokeredMessage->getContentType()
            );
        }
        
        $brokerProperties = $brokeredMessage->getBrokerProperties();
        if (!is_null($brokerProperties))
        {
            $httpCallContext->addHeader(
                Resources::BROKER_PROPERTIES,
                $brokerProperties->ToString()
            );
        } 

        $httpCallContext->setBody($brokeredMessage->getBody());
        $this->sendContext($httpCallContext);
    }

    /**
     * Sends a queue message. 
     * 
     * @param string           $path            The path to send message.
     * @param \BrokeredMessage $brokeredMessage The brokered message. 
     *
     * @return none
     */
    public function sendQueueMessage($path, $brokeredMessage)
    {
        $this->sendMessage($path, $brokeredMessage);
    }
    
    /**
     * Receives a queue message. 
     * 
     * @param string                $queuePath             The path of the
     * queue. 
     * @param ReceiveMessageOptions $receiveMessageOptions The options to 
     * receive the message. 
     *
     * @return BrokeredMessage
     */
    public function receiveQueueMessage($queuePath, $receiveMessageOptions)
    {
        $queueMessagePath = sprintf(Resources::QUEUE_MESSAGE_PATH, $queuePath);
        return receiveMessage($queueMessagePath, $receiveMessageOptions);
    }

    /**
     * Receives a message. 
     * 
     * @param string                 $path                  The path of the 
     * message. 
     * @param ReceivedMessageOptions $receiveMessageOptions The options to 
     * receive the message. 
     *
     * @return BrokeredMessage
     */
    public function receiveMessage($path, $receiveMessageOptions)
    {
        $httpCallContext = new HttpCallContext();
        $timeout = $receiveMessageOptions->getTimeout();
        if (!is_null($timeout))
        {
            $httpCallContext->addQueryParameter('timeout', $timeout);
        }

        if ($receiveMessageOptions->getIsReceiveAndDelete()) {
            $httpCallContext->setMethod(Resources::HTTP_DELETE);
        }
        else if ($receiveMessageOptions->getIsPeekLock()) {
            $httpCallContext->setMethod(Resources::HTTP_POST);
        }
        else {
            throw new ArgumentException(
                'The receive message option is in an unknown mode.'
            );
        }

        $response = $this->sendContext($httpCallContext);
        
        $responseHeaders = $response->getHeader(); 
        if (array_key_exists('BrokerProperties', $resonseHeaders))
        {
            $brokerProperties = BrokerProperties::create(
                $responseHeaders['BrokerProperties']
            );
        }
        else {
            $brokerProperties = new BrokerProperties();
        }
        
        if (array_key_exists('Location', $responseHeaders))
        {
            $brokerProperties->setLockLocation(
                $responseHeaders['Location']);
        }
        
        $brokeredMessage = new BrokeredMessage($brokerProperties);
        
        if (array_key_exists(Resources::CONTENT_TYPE, $responseHeaders))
        {
            $brokeredMessage->setContentType($responseHeaders[Resources::CONTENT_TYPE]);
        }

        if (array_key_exists('Date', $responseHeaders))
        {
            $brokeredMessage->setDate($responseHeaders['Date']);
        }

        $brokeredMessage->setBody($respose->getBody());

        foreach (array_keys($responseHeaders) as $headerKey)
        {
            $brokeredMessage->setProperty(
                $headerKey, 
                $responseHeaders[$headerKey]
            );
        }
        
       return $brokeredMessage; 
    }

    /**
     * Sends a brokered message to a specified topic. 
     * 
     * @param string          $topicName       The name of the topic. 
     * @param BrokeredMessage $brokeredMessage The brokered message. 
     *
     * @return none
     */
    public function sendTopicMessage($topicName, $brokeredMessage)
    {
        $this->sendMessage($topicName);
    } 

    /**
     * Receives a subscription message. 
     * 
     * @param string                $topicName             The name of the 
     * topic.
     * @param string                $subscriptionName      The name of the 
     * subscription.
     * @param ReceiveMessageOptions $receiveMessageOptions The options to 
     * receive the subscription message. 
     *
     * @return ReceiveSubscriptionMessageResult
     */
    public function receiveSubscriptionMessage(
        $topicName, 
        $subscriptionName, 
        $receiveMessageOptions
    ) {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_GET);
        $messagePath = sprintf(
            Resources::SUBSCRIPTION_MESSAGE_PATH, 
            $topicName,
            $subscriptionName
        );
        $httpCallContext->setPath($messagePath);
        $httpCallContext->addStatusCode(Resouces::STATUS_OK);

        $response = $this->sendContext($httpCallContext); 

        $receiveSubscriptionMessageResult 
            = ReceiveSubscriptionMessageResult::create($response->getBody());

        return $receiveSubscriptionMessageResult;
    }

    /**
     * Unlocks a brokered message. 
     * 
     * @param BrokeredMessage $brokeredMessage The brokered message. 
     *
     * @return none
     */
    public function unlockMessage($brokeredMessage)
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_PUT);
        $httpCallContext->setPath($brokeredMessage->getLockLocation());
        $httpCallContext->addStatusCode(Resources::STATUS_OK);
        $this->sendContext($httpCallContext);
    }
    
    /**
     * Deletes a brokered message. 
     * 
     * @param BrokeredMessage $brokeredMessage The borkered message.
     *
     * @return none
     */
    public function deleteMessage($brokeredMessage)
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_DELETE);
        $httpCallContext->setPath($brokeredMessage->getLockLocation());
        $httpCallContext->addStatusCode(Resources::STATUS_OK);
        $this->sendContext($httpCallContext);
    }
   
    /**
     * Creates a queue with specified queue info. 
     * 
     * @param QueueInfo $queueInfo The information of the queue.
     *
     * @return CreateQueueResult
     */
    public function createQueue($queueInfo)
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_PUT);
        $httpCallContext->setPath($queueInfo->getName());
        $httpCallContext->addStatusCode(Resources::STATUS_CREATED);
        
        $queueDescriptionXml = XmlSerializer::objectSerialize(
            $queueInfo->getQueueDescription(),
            'QueueDescription'
        );

        $entry = new Entry();
        $content = new Content();
        $content->setText($queueDescriptionXml);
        $entry->setContent($content);
        $httpCallContext->setBody($entry->toXml());
        $response = $this->sendContext($httpCallContext);
        $createQueueResult = CreateQueueResult::create($response->getBody());
        return $createQueueResult;
    } 

    /**
     * Deletes a queue. 
     * 
     * @param string $queuePath The path of the queue.
     *
     * @return none
     */
    public function deleteQueue($queuePath)
    {
        Validate::isString($queuePath, 'queuePath');
        Validate::notNullOrEmpty($queuePath, 'queuePath');
        
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_DELETE);
        $httpCallContext->addStatusCode(Resources::STATUS_OK);
        $httpCallContext->setPath($queuePath);
        
        $this->sendContext($httpCallContext);
    }

    /**
     * Gets a queue with specified path. 
     * 
     * @param string $queuePath The path of the queue.
     *
     * @throws Exception 
     * @return none
     */
    public function getQueue($queuePath)
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setPath($queuePath);
        $httpCallContext->setMethod(Resources::HTTP_GET);
        $response = $this->sendContext($httpCallContext);
        $getQueueResult = GetQueueResult::create($response->getBody());
        return $getQueueResult;
    }

    /**
     * Lists a queue. 
     * 
     * @param ListQueueOptions $listQueueOptions The options to list the 
     * queues.
     *
     * @throws Exception 
     * @return ListQueuesResult;
     */
    public function listQueues($listQueueOptions)
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(ResourceS::HTTP_GET);
        $httpCallContext->setPath(Resources::LIST_QUEUE_PATH);
        $response = $this->sendContext($httpCallContext);
        $listQueueResult = ListQueuesResult::create($response->getBody());
        return $listQueueResult;
    }

    /**
     * Creates a topic with specified topic info.  
     * 
     * @param  TopicInfo $topicInfo The information of the topic. 
     *
     * @throws Exception 
     *
     * @return CreateTopicResult 
     */
    public function createTopic($topicInfo)
    {
        Validate::notNullOrEmpty($topicInfo);
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_PUT);
        $httpCallContext->addStatusCode(Resources::STATUS_CREATED);
        $httpCallContext->setPath($topicInfo->getName());
        $httpCallContext->addHeader(
            Resources::CONTENT_TYPE,
            Resources::ATOM_ENTRY_CONTENT_TYPE
        );
        
        $topicDescriptionAttributes = array(
            'xmlns:i' => 'http://www.w3.org/2001/XMLSchema-instace',
            'xmlns' => 'http://schemas.microsoft.com/netservices/2010/10/servicebus/connect'
        );

        $content = new Content(
            XmlSerializer::objectSerialize(
                $topicInfo->getTopicDescription(), 
                'TopicDescription',
                $topicDescriptionAttributes
            )
        );
        $content->setType('application/xml');

        $entry = new Entry();
        $entry->setContent($content);
        $entry->setTitle($topicInfo->getName());

        $httpCallContext->setBody($entry->toXml());

        $response = $this->sendContext($httpCallContext);

        return CreateTopicResult::create($response->getBody());
    } 

    /**
     * Deletes a topic with specified topic path. 
     * 
     * @param string $topicPath The path of the topic.
     *
     * @return none
     */
    public function deleteTopic($topicPath)
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_DELETE);
        $httpCallContext->setPath($topicPath);     
        $httpCallContext->addStatusCode(Resources::STATUS_OK);
        
        $this->sendContext($httpCallContext);
    }
    
    /**
     * Gets a topic. 
     * 
     * @param string $topicPath The path of the topic.
     *
     * @return GetTopicResult;
     */
    public function getTopic($topicPath) 
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_GET);
        $httpCallContext->setPath($topicPath);
        $httpCallContext->addStatusCode(Resources::STATUS_OK);
        $response = $this->sendContext($httpCallContext);
        $getTopicResult = GetTopicResult::create($response->getBody());
        return $getTopicResult; 
    }
    
    /**
     * Lists topics. 
     * 
     * @param ListTopicsOptions $listTopicsOptions The options to list 
     * the topics. 
     *
     * @return ListTopicsResults
     */
    public function listTopics($listTopicsOptions) 
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setPath(Resources::LIST_TOPIC_PATH);
        $response = $this->sendContext($httpCallContext);
        $listTopicResult = ListTopicResult::create(
            $response->getBody()
        );
        return $listTopicResult; 
    }

    /**
     * Creates a subscription with specified topic path and 
     * subscription info. 
     * 
     * @param string                  $topicPath               The path of
     * the topic.
     * @param SubscriptionDescription $subscriptionDescription The description
     * of the subscription.
     *
     * @return CreateSubscriptionResult
     */
    public function createSubscription($topicPath, $subscriptionInfo) 
    {
        $httpCallContext = new HttpCallContext(); 
        $httpCallContext->setMethod(Resources::HTTP_PUT);
        $subscriptionPath = sprintf(
            Resources::SUBSCRIPTION_PATH, 
            $topicPath,
            $subscriptionInfo->getName()
        );
        $httpCallContext->setPath($subscriptionPath);
        $httpCallContext->setContentType(Resources::ATOM_ENTRY_CONTENT_TYPE);

   
        $response = $this->send($httpCallContext);
        $createSubscriptionResult = CreateSubscriptionResult::create($response->getBody());
        return $createSubscriptionResult;
    }

    /**
     * Deletes a subscription. 
     * 
     * @param string $topicPath        The path of the topic.
     * @param string $subscriptionName The name of the subscription.
     *
     * @return none
     */
    public function deleteSubscription($topicPath, $subscriptionName) 
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_DELETE);
        $subscriptionPath = sprintf(
            Resources::SUBSCRIPTION_PATH,
            $topicPath,
            $subscriptionName
        );
        $httpCallContext->setPath($subscriptionPath);
        $this->send($httpCallContext);
    }
    
    /**
     * Gets a subscription. 
     * 
     * @param string $topicPath        The path of the topic.
     * @param string $subscriptionName The name of the subscription.
     *
     * @return GetSubscriptionResult
     */
    public function getSubscription($topicPath, $subscriptionName) 
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_GET);
        $httpCallContext->addStatusCode(Resources::STATUS_OK);
        $subscriptionPath = sprintf(
            Resources::SUBSCRIPTION_PATH,
            $topicPath,
            $subscriptionName
        );
        $httpCallContext->setPath($subscriptionPath);
        $response = $this->sendContext($httpCallContext);
        $getSubscriptionResult = GetSubscriptionResult::create($response->getBody()); 
        return $getSubscriptionResult;
    }

    /**
     * Lists subscription. 
     * 
     * @param string                  $topicPath               The path of 
     * the topic.
     * @param ListSubscriptionOptions $listSubscriptionOptions The options
     * to list the subscription. 
     *
     * @return ListSubscription
     */
    public function listSubscription($topicPath, $listSubscriptionOptions) 
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_GET);
        $httpCallContext->setPath(Resources::LIST_SUBSCRIPTION_PATH);
        $httpCallContext->setContentType(Resources::ATOM_ENTRY_CONTENT_TYPE);
        $response = $this->send($httpCallContext);
        $listSubscriptionResult = ListSubscriptionResult::create($response->getBody());
        return $listSubscriptionResult; 
    }

    /**
     * Creates a rule. 
     * 
     * @param string          $topicPath        The path of the topic.
     * @param string          $subscriptionName The name of the subscription. 
     * @param RuleDescription $ruleDescription  The description of the rule.
     *
     * @throws Exception 
     * @return CreateRuleResult;
     */
    public function createRule($topicPath, $subscriptionName, $ruleDescription)
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_PUT);
        $httpCallContext->addStatusCode(Resources::STATUS_CREATED);
        $httpCallContext->setContentType(Resources::ATOM_ENTRY_CONTENT_TYPE);
        $rulePath = sprintf(
            Resources::RULE_PATH,
            $topicPath,
            $subscriptionName,
            $ruleDescription->getName()
        );
        $httpCallContext->setPath($rulePath);
        $response = $this->sendContext($httpCallContext);
        $createRuleResult = CreateRuleResult::create($response->getBody()); 
        return $createRuleResult;
    }

    /**
     * Deletes a rule. 
     * 
     * @param string $topicPath        The path of the topic.
     * @param string $subscriptionName The name of the subscription.
     * @param string $ruleName         The name of the rule.
     *
     * @return none
     */
    public function deleteRule($topicPath, $subscriptionName, $ruleName) 
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_DELETE);
        $rulePath = sprintf(
            Resources::RULE_PATH,
            $topicPath,
            $subscriptionName,
            $ruleDescription->getName()
        );
        $httpCallContext->setPath($rulePath);
        $this->sendContext($httpCallContext);
    }

    /**
     * Gets a rule. 
     * 
     * @param string $topicPath        The path of the topic.
     * @param string $subscriptionName The name of the subscription.
     * @param string $ruleName         The name of the rule.
     *
     * @return GetRuleResult
     */
    public function getRule($topicPath, $subscriptionName, $ruleName) 
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_GET);
        $rulePath = sprintf(
            Resources::RULE_PATH,
            $topicPath,
            $subscriptionName,
            $ruleName
        );
        $httpCallContext->setPath($rulePath);
        $response = $this->sendContext($httpCallContext);
        $getRuleResult = GetRuleResult::create($response->getBody());
        return $getRuleResult;
    }

    /**
     * Lists rules. 
     * 
     * @param string           $topicPath        The path of the topic.
     * @param string           $subscriptionName The name of the subscription.
     * @param ListRulesOptions $listRulesOptions The options to list the rules.
     *
     * @return ListRuleResult
     */
    public function listRules($topicPath, $subscriptionName, $listRulesOptions) 
    {
        $httpCallContext = new HttpCallContext();
        $httpCallContext->setMethod(Resources::HTTP_GET);
        $ruleName = $listRulesOptions->getName();
        $rulePath = sprintf(
            Resources::RULE_PATH,
            $topicPath,
            $subscriptionName,
            $ruleName
        );

        $httpCallContext->setPath($rulePath);
        $response = $this->sendContext($httpCallContext);
        $listRuleResult = ListRuleResult::create($response->getBody());

        return $listRuleResult;
    }
    
}
