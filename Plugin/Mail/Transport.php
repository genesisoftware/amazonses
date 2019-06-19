<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Smtp
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Jn2\AmazonSes\Plugin\Mail;

use Closure;
use Aws\Exception\AwsException;
use Magento\Framework\Mail\TransportInterface;
use Psr\Log\LoggerInterface;
use Aws\Ses\SesClientFactory;
use Aws\Credentials\CredentialsFactory;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use Zend\Mail\Message;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Class Transport
 * @package Jn2\AmazonSes\Smtp\Mail
 */
class Transport
{
    const API_VERSION = '2010-12-01';

    /**
     * @var int Store Id
     */
    protected $_storeId;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var SesClientFactory
     */
    protected $ses;

    /**
     * @var CredentialsFactory
     */
    protected $credentials;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    protected $encryptor;

    /**
     * Transport constructor.
     * @param Data $helper
     * @param LoggerInterface $logger
     */
    public function __construct(
        SesClientFactory $ses,
        CredentialsFactory $credentials,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        EncryptorInterface $encryptor
    ) {
        $this->ses = $ses;
        $this->credentials = $credentials;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->encryptor = $encryptor;
    }

    /**
     * @param TransportInterface $subject
     * @param Closure $proceed
     * @throws AwsException
     */
    public function aroundSendMessage(
        TransportInterface $subject,
        Closure $proceed
    ) {

        $enabled = $this->scopeConfig->isSetFlag('amazonses/configuration_option/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        if ($enabled) {

            $accesskey = $this->scopeConfig->getValue('amazonses/configuration_option/accesskey', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            $secretkey = $this->scopeConfig->getValue('amazonses/configuration_option/secretkey', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            // Retira criptografia
            $secretkey = $this->encryptor->decrypt($secretkey);

            $server = $this->scopeConfig->getValue('amazonses/configuration_option/host', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

            $this->credentials = $this->credentials->create([
                'key' => $accesskey,
                'secret' => $secretkey
            ]);

            $this->ses = $this->ses->create(['args' =>
                    ['credentials' => $this->credentials,
                        'version' => self::API_VERSION,
                        'region' => $server
                    ]
                ]
            );

            $message = $subject->getMessage();
            $message = Message::fromString($message->getRawMessage())->setEncoding('utf-8');

            $from = $message->getHeaders()->get('from')->getFieldValue();
            $to = $message->getHeaders()->get('to')->getFieldValue();
            $subject = $message->getHeaders()->get('subject')->getFieldValue();
            $body = $message->getBody();
            $boundary = sha1(rand() . time() . 'jn2');

            $msg = $this->createMessage($subject, $from, $to, $boundary, $body);

            try {
                $result = $this->ses->sendRawEmail([
                    'Source' => $from,
                    'Destinations' => [$to],
                    'RawMessage' => [
                        'Data' => ($msg)
                    ],
                ]);
            } catch (AwsException $e) {
                // output error message if fails
                echo $e->getMessage();
                $this->logger->critical('The email was not sent. Error message: ' . $e->getAwsErrorMessage() . "\n", ['exception' => $e]);
            }

        } else {
            // Caso módulo desativado, não faz nada
            $proceed();
        }
    }

    /**
     * Creates raw email message
     * @param string $subject
     * @param string $from
     * @param string $to
     * @param string $boundary
     * @param string $body
     * @return string
     */
    protected function  createMessage($subject, $from, $to, $boundary, $body) {

        $msg = <<<EOE
Subject: {$subject}
MIME-Version: 1.0
Content-type: multipart/alternative; boundary="{$boundary}"
To: {$to}
From: {$from}


EOE;
        // Se for HTML
        if (strpos($body, '<html') !== false ||
            strpos($body, '!DOCTYPE html') !== false ||
            strpos($body, '<body') !== false)
        {
            $msg .= <<<EOE
--{$boundary}
Content-Type: text/html; charset=iso-8859-1

{$body}

EOE;
        // Texto simples
        } else {
            $msg .= <<<EOE
--{$boundary}
Content-Type: text/plain;

{$body}

EOE;

        }
        return $msg;
    }
}
