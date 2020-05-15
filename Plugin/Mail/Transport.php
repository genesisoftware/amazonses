<?php
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
use Zend\Mime\Mime;

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
            $ses = $this->createAccess();

            $message = $subject->getMessage();

            $from = $this->createFrom($message);
            $to = $this->createTo($message);
            $subject = $message->getSubject();
            $body = $message->getBody()->getParts()[0]->getRawContent();
            $boundary = sha1(rand() . time() . 'jn2');

            if(isset($message->getReplyTo()[0])){
                $replyTo = $this->createReplyTo($message);
            } else {
                $replyTo = $from;
            }

            $msg = $this->createMessage($subject, $from, $to, $boundary, $body, $replyTo);

            try {
                $result = $ses->sendRawEmail([
                    'Source' => $from,
                    'Destinations' => [$to],
                    'RawMessage' => [
                        'Data' => ($msg)
                    ],
                ]);
            } catch (AwsException $e) {
                // output error message if fails
                $this->logger->critical('The email was not sent. Error message: ' . $e->getAwsErrorMessage() . "\n", ['exception' => $e]);
            }

        } else {
            // Caso módulo desativado, não faz nada
            $proceed();
        }
    }

    /**
     * Cria From
     *
     * @param $message
     * @return string
     */
    protected function createFrom($message) {
        $from = '';
        $nameFrom = $message->getFrom()[0]->getName();
        if (!empty($nameFrom)) {
            $from .= $nameFrom . ' <';
        }
        $from .= $message->getFrom()[0]->getEmail();
        if(!empty($nameFrom)) {
            $from .= '>';
        }
        return $from;
    }

    /**
     * Cria To
     *
     * @param $message
     * @return string
     */
    protected function createTo($message) {
        $to = '';
        $nameTo = $message->getTo()[0]->getName();
        if (!empty($nameTo)) {
            $to .= $nameTo . ' <';
        }
        $to .= $message->getTo()[0]->getEmail();
        if(!empty($nameTo)) {
            $to .= '>';
        }
        return $to;
    }

    /**
     * Cria Reply To
     *
     * @param $message
     * @return string
     */
    protected function createReplyTo($message) {
        $replyTo = '';
        $nameTo = $message->getReplyTo()[0]->getName();
        if (!empty($nameTo)) {
            $replyTo .= $nameTo . ' <';
        }
        $replyTo .= $message->getReplyTo()[0]->getEmail();
        if(!empty($nameTo)) {
            $replyTo .= '>';
        }
        return $replyTo;
    }

    /**
     * Cria acesso à API do AmazonSES
     *
     * @return \Aws\Ses\SesClient
     */
    protected function createAccess() {
        $accesskey = $this->scopeConfig->getValue('amazonses/configuration_option/accesskey', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $secretkey = $this->scopeConfig->getValue('amazonses/configuration_option/secretkey', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        // Retira criptografia
        $secretkey = $this->encryptor->decrypt($secretkey);

        $server = $this->scopeConfig->getValue('amazonses/configuration_option/host', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        $credentials = $this->credentials->create([
            'key' => $accesskey,
            'secret' => $secretkey
        ]);

        $ses = $this->ses->create(['args' =>
                ['credentials' => $credentials,
                    'version' => self::API_VERSION,
                    'region' => $server
                ]
            ]
        );
        return $ses;
    }

    /**
     * Creates raw email message
     * @param string $subject
     * @param string $from
     * @param string $to
     * @param string $boundary
     * @param string $body
     * @param $replyTo
     * @return string
     */
    protected function createMessage($subject, $from, $to, $boundary, $body, $replyTo) {
        $msg = <<<EOE
Subject: {$subject}
MIME-Version: 1.0
Content-type: multipart/alternative; boundary="{$boundary}"
To: {$to}
From: {$from}
Reply-To: {$replyTo}


--{$boundary}
Content-Type: text/html; charset=utf-8

{$body}

EOE;

        return $msg;
    }
}