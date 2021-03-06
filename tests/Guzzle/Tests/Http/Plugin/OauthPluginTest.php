<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Plugin\MockPlugin;
use Guzzle\Http\Plugin\OauthPlugin;
use Guzzle\Common\Event;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Response;

class OauthPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    const TIMESTAMP = '1327274290';

    protected $config = array(
        'consumer_key'    => 'foo',
        'consumer_secret' => 'bar',
        'token'           => 'count',
        'token_secret'    => 'dracula'
    );

    protected function getRequest()
    {
        return RequestFactory::create('POST', 'http://www.test.com/path?a=b&c=d', null, array(
            'e' => 'f'
        ));
    }

    /**
     * @covers Guzzle\Http\Plugin\OauthPlugin::getSubscribedEvents
     */
    public function testSubscribesToEvents()
    {
        $events = OauthPlugin::getSubscribedEvents();
        $this->assertArrayHasKey('request.before_send', $events);
    }

    /**
     * @covers Guzzle\Http\Plugin\OauthPlugin::__construct
     */
    public function testAcceptsConfigurationData()
    {
        $p = new OauthPlugin($this->config);

        // Access the config object
        $class = new \ReflectionClass($p);
        $property = $class->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($p);

        $this->assertEquals('foo', $config['consumer_key']);
        $this->assertEquals('bar', $config['consumer_secret']);
        $this->assertEquals('count', $config['token']);
        $this->assertEquals('dracula', $config['token_secret']);
        $this->assertEquals('1.0', $config['version']);
        $this->assertEquals('HMAC-SHA1', $config['signature_method']);
    }

    /**
     * @covers Guzzle\Http\Plugin\OauthPlugin::getStringToSign
     */
    public function testCreatesStringToSignFromPostRequest()
    {
        $p = new OauthPlugin($this->config);
        $request = $this->getRequest();
        $this->assertEquals(
            // Method and URL
            'POST&http%3A%2F%2Fwww.test.com%2Fpath%23' .
            // Sorted parameters from query string and body
            '&a%3Db%26c%3Dd%26e%3Df%26oauth_consumer_key%3Dfoo' .
            '%26oauth_nonce%3D29443585e9f85f23306198f7eae8d870c46c53ad%26' .
            'oauth_signature_method%3DHMAC-SHA1' .
            '%26oauth_timestamp%3D' . self::TIMESTAMP . '%26oauth_token%3Dcount%26oauth_version%3D1.0',
            $p->getStringToSign($request, self::TIMESTAMP)
        );
    }

    /**
     * @covers Guzzle\Http\Plugin\OauthPlugin::getStringToSign
     */
    public function testCreatesStringToSignFromPostRequestWithCustomContentType()
    {
        $p = new OauthPlugin($this->config);
        $request = $this->getRequest();
        $request->setHeader('Content-Type', 'Foo');
        $this->assertEquals(
            // Method and URL
            'POST&http%3A%2F%2Fwww.test.com%2Fpath%23' .
            // Sorted parameters from query string and body
            '&a%3Db%26c%3Dd%26oauth_consumer_key%3Dfoo' .
            '%26oauth_nonce%3D29443585e9f85f23306198f7eae8d870c46c53ad%26' .
            'oauth_signature_method%3DHMAC-SHA1' .
            '%26oauth_timestamp%3D' . self::TIMESTAMP . '%26oauth_token%3Dcount%26oauth_version%3D1.0',
            $p->getStringToSign($request, self::TIMESTAMP)
        );
    }

    /**
     * @covers Guzzle\Http\Plugin\OauthPlugin::getStringToSign
     * @depends testCreatesStringToSignFromPostRequest
     */
    public function testConvertsBooleansToStrings()
    {
        $p = new OauthPlugin($this->config);
        $request = $this->getRequest();
        $request->getQuery()->set('a', true);
        $request->getQuery()->set('c', false);
        $this->assertContains('&a%3Dtrue%26c%3Dfalse', $p->getStringToSign($request, self::TIMESTAMP));
    }

    /**
     * @covers Guzzle\Http\Plugin\OauthPlugin::getSignature
     * @depends testCreatesStringToSignFromPostRequest
     */
    public function testSignsStrings()
    {
        $p = new OauthPlugin(array_merge($this->config, array(
            'signature_callback' => function($string, $key) {
                return "_{$string}|{$key}_";
            }
        )));
        $request = $this->getRequest();
        $sig = $p->getSignature($request, self::TIMESTAMP);
        $this->assertEquals(
            '_POST&http%3A%2F%2Fwww.test.com%2Fpath%23&a%3Db%26c%3Dd%26e%3Df%26oauth_consumer_key%3Dfoo' .
            '%26oauth_nonce%3D29443585e9f85f23306198f7eae8d870c46c53ad%26oauth_signature_method%3DHMAC-SHA1' .
            '%26oauth_timestamp%3D' . self::TIMESTAMP . '%26oauth_token%3Dcount%26oauth_version%3D1.0|' .
            'bar&dracula_',
            base64_decode($sig)
        );
    }

    /**
     * @covers Guzzle\Http\Plugin\OauthPlugin::onRequestBeforeSend
     * @covers Guzzle\Http\Plugin\OauthPlugin::__construct
     */
    public function testSignsOauthRequests()
    {
        $p = new OauthPlugin($this->config);
        $event = new Event(array(
            'request' => $this->getRequest(),
            'timestamp' => self::TIMESTAMP
        ));
        $p->onRequestBeforeSend($event);

        $this->assertTrue($event['request']->hasHeader('Authorization'));
        $this->assertEquals('OAuth oauth_consumer_key="foo", '
            . 'oauth_nonce="29443585e9f85f23306198f7eae8d870c46c53ad", '
            . 'oauth_signature="IvVzcqLU%2Fk2zzoC7gBRfSnDkMdQ%3D", '
            . 'oauth_signature_method="HMAC-SHA1", '
            . 'oauth_timestamp="' . self::TIMESTAMP . '", '
            . 'oauth_token="count", '
            . 'oauth_version="1.0"',
            $event['request']->getHeader('Authorization')
        );
    }
}