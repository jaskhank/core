<?php
/**
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace OCA\Encryption\Tests\Crypto;


use OC\User\LoginException;
use OCA\Encryption\Crypto\Crypt;
use OCA\Encryption\Crypto\DecryptAll;
use OCA\Encryption\KeyManager;
use OCA\Encryption\Session;
use OCA\Encryption\Util;
use OCP\IUserManager;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Test\TestCase;
use Test\Traits\UserTrait;

/**
 * Class DecryptAllTest
 *
 * @group DB
 * @package OCA\Encryption\Tests\Crypto
 */
class DecryptAllTest extends TestCase {
	use UserTrait;

	/** @var  DecryptAll */
	protected $instance;

	/** @var Util | \PHPUnit_Framework_MockObject_MockObject  */
	protected $util;

	/** @var KeyManager | \PHPUnit_Framework_MockObject_MockObject  */
	protected $keyManager;

	/** @var  Crypt | \PHPUnit_Framework_MockObject_MockObject */
	protected $crypt;

	/** @var  Session | \PHPUnit_Framework_MockObject_MockObject */
	protected $session;

	protected $userManager;

	/** @var QuestionHelper | \PHPUnit_Framework_MockObject_MockObject  */
	protected $questionHelper;

	public function setUp() {
		parent::setUp();

		$this->util = $this->getMockBuilder('OCA\Encryption\Util')
			->disableOriginalConstructor()->getMock();
		$this->keyManager = $this->getMockBuilder('OCA\Encryption\KeyManager')
			->disableOriginalConstructor()->getMock();
		$this->crypt = $this->getMockBuilder('OCA\Encryption\Crypto\Crypt')
			->disableOriginalConstructor()->getMock();
		$this->session = $this->getMockBuilder('OCA\Encryption\Session')
			->disableOriginalConstructor()->getMock();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->questionHelper = $this->getMockBuilder('Symfony\Component\Console\Helper\QuestionHelper')
			->disableOriginalConstructor()->getMock();

		$this->instance = new DecryptAll(
			$this->util,
			$this->keyManager,
			$this->crypt,
			$this->session,
			$this->userManager,
			$this->questionHelper
		);
	}

	public function testUpdateSession() {
		$this->session->expects($this->once())->method('prepareDecryptAll')
			->with('user1', 'key1');

		$this->invokePrivate($this->instance, 'updateSession', ['user1', 'key1']);
	}

	/**
	 * @dataProvider dataTestGetPrivateKey
	 *
	 * @param string $user
	 * @param string $recoveryKeyId
	 */
	public function testGetPrivateKey($user, $recoveryKeyId, $masterKeyId) {
		$password = 'passwd';
		$recoveryKey = 'recoveryKey';
		$userKey = 'userKey';
		$unencryptedKey = 'unencryptedKey';

		$this->keyManager->expects($this->any())->method('getRecoveryKeyId')
			->willReturn($recoveryKeyId);

		if ($user === $recoveryKeyId) {
			$this->keyManager->expects($this->once())->method('getSystemPrivateKey')
				->with($recoveryKeyId)->willReturn($recoveryKey);
			$this->keyManager->expects($this->never())->method('getPrivateKey');
			$this->crypt->expects($this->once())->method('decryptPrivateKey')
				->with($recoveryKey, $password)->willReturn($unencryptedKey);
		} elseif ($user === $masterKeyId) {
			$this->keyManager->expects($this->once())->method('getSystemPrivateKey')
				->with($masterKeyId)->willReturn($masterKey);
			$this->keyManager->expects($this->never())->method('getPrivateKey');
			$this->crypt->expects($this->once())->method('decryptPrivateKey')
				->with($masterKey, $password, $masterKeyId)->willReturn($unencryptedKey);

		} else {
			$this->keyManager->expects($this->never())->method('getSystemPrivateKey');
			$this->keyManager->expects($this->once())->method('getPrivateKey')
				->with($user)->willReturn($userKey);
			$this->crypt->expects($this->once())->method('decryptPrivateKey')
				->with($userKey, $password, $user)->willReturn($unencryptedKey);
		}

		$this->assertSame($unencryptedKey,
			$this->invokePrivate($this->instance, 'getPrivateKey', [$user, $password])
		);
	}

	public function dataTestGetPrivateKey() {
		return [
			['user1', 'recoveryKey', 'masterKeyId'],
			['recoveryKeyId', 'recoveryKeyId', 'masterKeyId'],
			['masterKeyId', 'masterKeyId', 'masterKeyId']
		];
	}

	public function providerPrepareLoginException() {
		return [
			['user1'],
			['recoverykey'],
			['throwExceptionInCheckRecovery']
		];
	}
	/**
	 * @dataProvider providerPrepareLoginException
	 * @expectedException \OC\User\LoginException
	 * @expectedExceptionMessage Invalid credentials provided
	 */
	public function testPrepareLoginException($loginType) {
		$this->createUser('user1', 'pass');
		$input = $this->createMock(InputInterface::class);
		$output = $this->createMock(OutputInterface::class);

		$this->util->expects($this->once())
			->method('isMasterKeyEnabled')
			->willReturn(false);

		$this->keyManager->expects($this->any())
			->method('getRecoveryKeyId')
			->willReturn('xyz');

		$this->questionHelper->expects($this->any())
			->method('ask')
			->willReturn('foo');

		if ($loginType === 'user1') {
			$this->userManager->expects($this->any())
				->method('checkPassword')
				->willReturn(false);
			$this->instance->prepare($input, $output, 'user1');
		} elseif ($loginType === 'recoverykey') {
			$this->keyManager->expects($this->once())
				->method('checkRecoveryPassword')
				->willReturn(false);
			$this->instance->prepare($input, $output, 'xyz');
		} else {
			$this->keyManager->expects($this->once())
				->method('checkRecoveryPassword')
				->willThrowException(new \Exception());
			$this->instance->prepare($input, $output, 'xyz');
		}


	}
}
