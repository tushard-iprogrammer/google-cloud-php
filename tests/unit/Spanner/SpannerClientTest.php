<?php
/**
 * Copyright 2016 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Cloud\Tests\Unit\Spanner;

use Google\Cloud\Core\Int64;
use Google\Cloud\Core\Iterator\ItemIterator;
use Google\Cloud\Core\LongRunning\LongRunningOperation;
use Google\Cloud\Spanner\Admin\Database\V1\DatabaseAdminClient;
use Google\Cloud\Spanner\Admin\Instance\V1\InstanceAdminClient;
use Google\Cloud\Spanner\Bytes;
use Google\Cloud\Spanner\Connection\ConnectionInterface;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Date;
use Google\Cloud\Spanner\Duration;
use Google\Cloud\Spanner\Instance;
use Google\Cloud\Spanner\InstanceConfiguration;
use Google\Cloud\Spanner\KeyRange;
use Google\Cloud\Spanner\KeySet;
use Google\Cloud\Spanner\SpannerClient;
use Google\Cloud\Spanner\Timestamp;
use Google\Cloud\Core\Testing\GrpcTestTrait;
use Prophecy\Argument;
use PHPUnit\Framework\TestCase;

/**
 * @group spanner
 */
class SpannerClientTest extends TestCase
{
    use GrpcTestTrait;

    const PROJECT = 'my-awesome-project';
    const INSTANCE = 'inst';
    const DATABASE = 'db';
    const CONFIG = 'conf';

    private $client;

    public function setUp()
    {
        $this->checkAndSkipGrpcTests();

        $this->connection = $this->prophesize(ConnectionInterface::class);
        $this->client = \Google\Cloud\Core\Testing\TestHelpers::stub(SpannerClient::class, [
            ['projectId' => self::PROJECT]
        ]);
    }

    /**
     * @group spanneradmin
     */
    public function testInstanceConfigurations()
    {
        $this->connection->listInstanceConfigs(Argument::any())
            ->shouldBeCalled()
            ->willReturn([
                'instanceConfigs' => [
                    [
                        'name' => InstanceAdminClient::instanceConfigName(self::PROJECT, self::CONFIG),
                        'displayName' => 'Bar'
                    ], [
                        'name' => InstanceAdminClient::instanceConfigName(self::PROJECT, self::CONFIG),
                        'displayName' => 'Bat'
                    ]
                ]
            ]);

        $this->client->___setProperty('connection', $this->connection->reveal());

        $configs = $this->client->instanceConfigurations();

        $this->assertInstanceOf(ItemIterator::class, $configs);

        $configs = iterator_to_array($configs);
        $this->assertCount(2, $configs);
        $this->assertInstanceOf(InstanceConfiguration::class, $configs[0]);
        $this->assertInstanceOf(InstanceConfiguration::class, $configs[1]);
    }

    /**
     * @group spanneradmin
     */
    public function testPagedInstanceConfigurations()
    {
        $firstCall = [
            'instanceConfigs' => [
                [
                    'name' => 'projects/foo/instanceConfigs/bar',
                    'displayName' => 'Bar'
                ]
            ],
            'nextPageToken' => 'fooBar'
        ];

        $secondCall = [
            'instanceConfigs' => [
                [
                    'name' => 'projects/foo/instanceConfigs/bat',
                    'displayName' => 'Bat'
                ]
            ]
        ];

        $this->connection->listInstanceConfigs(Argument::any())
            ->shouldBeCalledTimes(2)
            ->willReturn($firstCall, $secondCall);

        $this->client->___setProperty('connection', $this->connection->reveal());

        $configs = $this->client->instanceConfigurations();

        $this->assertInstanceOf(ItemIterator::class, $configs);

        $configs = iterator_to_array($configs);
        $this->assertCount(2, $configs);
        $this->assertInstanceOf(InstanceConfiguration::class, $configs[0]);
        $this->assertInstanceOf(InstanceConfiguration::class, $configs[1]);
    }

    /**
     * @group spanneradmin
     */
    public function testInstanceConfiguration()
    {
        $config = $this->client->instanceConfiguration('bar');

        $this->assertInstanceOf(InstanceConfiguration::class, $config);
        $this->assertEquals('bar', InstanceAdminClient::parseName($config->name())['instance_config']);
    }

    /**
     * @group spanneradmin
     */
    public function testCreateInstance()
    {
        $this->connection->createInstance(Argument::that(function ($arg) {
            if ($arg['name'] !== InstanceAdminClient::instanceName(self::PROJECT, self::INSTANCE)) return false;
            return $arg['config'] === InstanceAdminClient::instanceConfigName(self::PROJECT, self::CONFIG);
        }))
            ->shouldBeCalled()
            ->willReturn([
                'name' => 'operations/foo'
            ]);

        $this->client->___setProperty('connection', $this->connection->reveal());

        $config = $this->prophesize(InstanceConfiguration::class);
        $config->name()->willReturn(InstanceAdminClient::instanceConfigName(self::PROJECT, self::CONFIG));

        $operation = $this->client->createInstance($config->reveal(), self::INSTANCE);

        $this->assertInstanceOf(LongRunningOperation::class, $operation);
    }

    /**
     * @group spanneradmin
     */
    public function testInstance()
    {
        $i = $this->client->instance('foo');
        $this->assertInstanceOf(Instance::class, $i);
        $this->assertEquals('foo', InstanceAdminClient::parseName($i->name())['instance']);
    }

    /**
     * @group spanneradmin
     */
    public function testInstanceWithInstanceArray()
    {
        $i = $this->client->instance('foo', ['key' => 'val']);
        $this->assertEquals('val', $i->info()['key']);
    }

    /**
     * @group spanneradmin
     */
    public function testInstances()
    {
        $this->connection->listInstances(Argument::any())
            ->shouldBeCalled()
            ->willReturn([
                'instances' => [
                    ['name' => 'projects/test-project/instances/foo'],
                    ['name' => 'projects/test-project/instances/bar'],
                ]
            ]);

        $this->client->___setProperty('connection', $this->connection->reveal());

        $instances = $this->client->instances();
        $this->assertInstanceOf(ItemIterator::class, $instances);

        $instances = iterator_to_array($instances);
        $this->assertCount(2, $instances);
        $this->assertEquals('foo', InstanceAdminClient::parseName($instances[0]->name())['instance']);
        $this->assertEquals('bar', InstanceAdminClient::parseName($instances[1]->name())['instance']);
    }

    /**
     * @group spanneradmin
     */
    public function testResumeOperation()
    {
        $opName = 'operations/foo';

        $op = $this->client->resumeOperation($opName);
        $this->assertInstanceOf(LongRunningOperation::class, $op);
        $this->assertEquals($op->name(), $opName);
    }

    public function testConnect()
    {
        $database = $this->client->connect(self::INSTANCE, self::DATABASE);
        $this->assertInstanceOf(Database::class, $database);
        $this->assertEquals(self::DATABASE, DatabaseAdminClient::parseName($database->name())['database']);
    }

    public function testConnectWithInstance()
    {
        $inst = $this->client->instance(self::INSTANCE);
        $database = $this->client->connect($inst, self::DATABASE);
        $this->assertInstanceOf(Database::class, $database);
        $this->assertEquals(self::DATABASE, DatabaseAdminClient::parseName($database->name())['database']);
    }

    public function testKeyset()
    {
        $ks = $this->client->keySet();
        $this->assertInstanceOf(KeySet::class, $ks);
    }

    public function testKeyRange()
    {
        $kr = $this->client->keyRange();
        $this->assertInstanceOf(KeyRange::class, $kr);
    }

    public function testBytes()
    {
        $b = $this->client->bytes('foo');
        $this->assertInstanceOf(Bytes::class, $b);
        $this->assertEquals(base64_encode('foo'), (string)$b);
    }

    public function testDate()
    {
        $d = $this->client->date(new \DateTime);
        $this->assertInstanceOf(Date::class, $d);
    }

    public function testTimestamp()
    {
        $ts = $this->client->timestamp(new \DateTime);
        $this->assertInstanceOf(Timestamp::class, $ts);
    }

    public function testInt64()
    {
        $i64 = $this->client->int64('123');
        $this->assertInstanceOf(Int64::class, $i64);
    }

    public function testDuration()
    {
        $d = $this->client->duration(10, 1);
        $this->assertInstanceOf(Duration::class, $d);
    }
}
