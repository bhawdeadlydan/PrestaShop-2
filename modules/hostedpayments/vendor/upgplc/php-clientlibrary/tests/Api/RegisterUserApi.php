<?php

namespace Upg\Library\Tests\Api;

use Upg\Library\Api\RegisterUser as RegisterUserApi;
use Upg\Library\Request\RegisterUser;
use Upg\Library\Config;

class RegisterUserTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Config object for tests
     * @var Config
     */
    private $config;

    public function setUp()
    {
        $this->config = new Config(array(
            'merchantPassword' => '8A!v#6qPc3?+G1on',
            'merchantID' => '123',
            'storeID' => 'test Store',
            'sendRequestsWithSalt' => true,
            'baseUrl' => "http://www.something.com/"
        ));
    }

    public function tearDown()
    {
        unset($this->config);
    }

    public function testGetUrl()
    {
        $request = new RegisterUser($this->config);

        $api = new RegisterUserApi($this->config, $request);

        $this->assertEquals('http://www.something.com/registerUser', $api->getUrl());
    }
}
