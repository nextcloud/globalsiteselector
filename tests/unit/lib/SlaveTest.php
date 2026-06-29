<?php
declare(strict_types=1);

namespace OCA\GlobalSiteSelector\Tests\Unit;

use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\Lookup;
use OCA\GlobalSiteSelector\Service\SlaveService;
use OCA\GlobalSiteSelector\Slave;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SlaveTest extends TestCase {
    private $client;
    private $slaveService;
    private Slave $slave;

    protected function setUp(): void {
        parent::setUp();

        $clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);
        $clientService->method('newClient')->willReturn($this->client);

        $this->slaveService = $this->createMock(SlaveService::class);
        $lookup = $this->createMock(Lookup::class);
        $lookup->method('configureClient')->willReturnArgument(0); // pass options through

        $gss = $this->createMock(GlobalSiteSelector::class);
        $gss->method('isSlave')->willReturn(true);            // checkConfiguration() passes
        $gss->method('getLookupServerUrl')->willReturn('https://lookup.test');
        $gss->method('getMode')->willReturn('slave');
        $gss->method('getJwtKey')->willReturn('secret');

        $this->slave = new Slave(
            $this->createMock(IUserManager::class),
            $clientService,
            $this->slaveService,
            $lookup,
            $gss,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IConfig::class),
        );
    }

    public function testEnabledUserIsPushed(): void {
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $user->method('getCloudId')->willReturn('alice@slave.test');
        $this->slaveService->method('getAccountData')->willReturn(['id' => 'alice@slave.test']);

        $this->client->expects($this->once())->method('post');
        $this->client->expects($this->never())->method('delete');

        $this->slave->updateUser($user);
    }

    public function testDisabledUserIsRemoved(): void {
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(false);
        $user->method('getCloudId')->willReturn('bob@slave.test');

        $this->client->expects($this->never())->method('post');
        $this->client->expects($this->once())->method('delete')->with(
            $this->anything(),
            $this->callback(function (array $opts): bool {
                $body = json_decode($opts['body'] ?? '{}', true);
                return in_array('bob@slave.test', $body['users'] ?? [], true);
            })
        );

        $this->slave->updateUser($user);
    }
}