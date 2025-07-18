<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Files_Versions\Tests;

use OC\AllConfig;
use OC\Files\Cache\Watcher;
use OC\Files\Filesystem;
use OC\Files\Storage\Temporary;
use OC\Files\View;
use OC\SystemConfig;
use OC\User\NoUserException;
use OCA\Files_Sharing\AppInfo\Application;
use OCA\Files_Versions\Db\VersionEntity;
use OCA\Files_Versions\Db\VersionsMapper;
use OCA\Files_Versions\Events\VersionRestoredEvent;
use OCA\Files_Versions\Storage;
use OCA\Files_Versions\Versions\IVersionManager;
use OCP\Constants;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IMimeTypeLoader;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IShare;
use OCP\Util;

/**
 * Class Test_Files_versions
 * this class provide basic files versions test
 *
 * @group DB
 */
class VersioningTest extends \Test\TestCase {
	public const TEST_VERSIONS_USER = 'test-versions-user';
	public const TEST_VERSIONS_USER2 = 'test-versions-user2';
	public const USERS_VERSIONS_ROOT = '/test-versions-user/files_versions';

	/**
	 * @var View
	 */
	private $rootView;
	/**
	 * @var VersionsMapper
	 */
	private $versionsMapper;
	/**
	 * @var IMimeTypeLoader
	 */
	private $mimeTypeLoader;
	private $user1;
	private $user2;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$application = new Application();

		// create test user
		self::loginHelper(self::TEST_VERSIONS_USER2, true);
		self::loginHelper(self::TEST_VERSIONS_USER, true);
	}

	public static function tearDownAfterClass(): void {
		// cleanup test user
		$user = Server::get(IUserManager::class)->get(self::TEST_VERSIONS_USER);
		if ($user !== null) {
			$user->delete();
		}
		$user = Server::get(IUserManager::class)->get(self::TEST_VERSIONS_USER2);
		if ($user !== null) {
			$user->delete();
		}

		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();

		$config = Server::get(IConfig::class);
		$mockConfig = $this->getMockBuilder(AllConfig::class)
			->onlyMethods(['getSystemValue'])
			->setConstructorArgs([Server::get(SystemConfig::class)])
			->getMock();
		$mockConfig->expects($this->any())
			->method('getSystemValue')
			->willReturnCallback(function ($key, $default) use ($config) {
				if ($key === 'filesystem_check_changes') {
					return Watcher::CHECK_ONCE;
				} else {
					return $config->getSystemValue($key, $default);
				}
			});
		$this->overwriteService(AllConfig::class, $mockConfig);

		// clear hooks
		\OC_Hook::clear();
		\OC::registerShareHooks(Server::get(SystemConfig::class));
		\OC::$server->boot();

		self::loginHelper(self::TEST_VERSIONS_USER);
		$this->rootView = new View();
		if (!$this->rootView->file_exists(self::USERS_VERSIONS_ROOT)) {
			$this->rootView->mkdir(self::USERS_VERSIONS_ROOT);
		}

		$this->versionsMapper = Server::get(VersionsMapper::class);
		$this->mimeTypeLoader = Server::get(IMimeTypeLoader::class);

		$this->user1 = $this->createMock(IUser::class);
		$this->user1->method('getUID')
			->willReturn(self::TEST_VERSIONS_USER);
		$this->user2 = $this->createMock(IUser::class);
		$this->user2->method('getUID')
			->willReturn(self::TEST_VERSIONS_USER2);
	}

	protected function tearDown(): void {
		$this->restoreService(AllConfig::class);

		if ($this->rootView) {
			$this->rootView->deleteAll(self::TEST_VERSIONS_USER . '/files/');
			$this->rootView->deleteAll(self::TEST_VERSIONS_USER2 . '/files/');
			$this->rootView->deleteAll(self::TEST_VERSIONS_USER . '/files_versions/');
			$this->rootView->deleteAll(self::TEST_VERSIONS_USER2 . '/files_versions/');
		}

		\OC_Hook::clear();

		parent::tearDown();
	}

	/**
	 * @medium
	 * test expire logic
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('versionsProvider')]
	public function testGetExpireList($versions, $sizeOfAllDeletedFiles): void {

		// last interval end at 2592000
		$startTime = 5000000;

		$testClass = new VersionStorageToTest();
		[$deleted, $size] = $testClass->callProtectedGetExpireList($startTime, $versions);

		// we should have deleted 16 files each of the size 1
		$this->assertEquals($sizeOfAllDeletedFiles, $size);

		// the deleted array should only contain versions which should be deleted
		foreach ($deleted as $key => $path) {
			unset($versions[$key]);
			$this->assertEquals('delete', substr($path, 0, strlen('delete')));
		}

		// the versions array should only contain versions which should be kept
		foreach ($versions as $version) {
			$this->assertEquals('keep', $version['path']);
		}
	}

	public static function versionsProvider(): array {
		return [
			// first set of versions uniformly distributed versions
			[
				[
					// first slice (10sec) keep one version every 2 seconds
					['version' => 4999999, 'path' => 'keep', 'size' => 1],
					['version' => 4999998, 'path' => 'delete', 'size' => 1],
					['version' => 4999997, 'path' => 'keep', 'size' => 1],
					['version' => 4999995, 'path' => 'keep', 'size' => 1],
					['version' => 4999994, 'path' => 'delete', 'size' => 1],
					//next slice (60sec) starts at 4999990 keep one version every 10 secons
					['version' => 4999988, 'path' => 'keep', 'size' => 1],
					['version' => 4999978, 'path' => 'keep', 'size' => 1],
					['version' => 4999975, 'path' => 'delete', 'size' => 1],
					['version' => 4999972, 'path' => 'delete', 'size' => 1],
					['version' => 4999967, 'path' => 'keep', 'size' => 1],
					['version' => 4999958, 'path' => 'delete', 'size' => 1],
					['version' => 4999957, 'path' => 'keep', 'size' => 1],
					//next slice (3600sec) start at 4999940 keep one version every 60 seconds
					['version' => 4999900, 'path' => 'keep', 'size' => 1],
					['version' => 4999841, 'path' => 'delete', 'size' => 1],
					['version' => 4999840, 'path' => 'keep', 'size' => 1],
					['version' => 4999780, 'path' => 'keep', 'size' => 1],
					['version' => 4996401, 'path' => 'keep', 'size' => 1],
					// next slice (86400sec) start at 4996400 keep one version every 3600 seconds
					['version' => 4996350, 'path' => 'delete', 'size' => 1],
					['version' => 4992800, 'path' => 'keep', 'size' => 1],
					['version' => 4989800, 'path' => 'delete', 'size' => 1],
					['version' => 4989700, 'path' => 'delete', 'size' => 1],
					['version' => 4989200, 'path' => 'keep', 'size' => 1],
					// next slice (2592000sec) start at 4913600 keep one version every 86400 seconds
					['version' => 4913600, 'path' => 'keep', 'size' => 1],
					['version' => 4852800, 'path' => 'delete', 'size' => 1],
					['version' => 4827201, 'path' => 'delete', 'size' => 1],
					['version' => 4827200, 'path' => 'keep', 'size' => 1],
					['version' => 4777201, 'path' => 'delete', 'size' => 1],
					['version' => 4777501, 'path' => 'delete', 'size' => 1],
					['version' => 4740000, 'path' => 'keep', 'size' => 1],
					// final slice starts at 2408000 keep one version every 604800 secons
					['version' => 2408000, 'path' => 'keep', 'size' => 1],
					['version' => 1803201, 'path' => 'delete', 'size' => 1],
					['version' => 1803200, 'path' => 'keep', 'size' => 1],
					['version' => 1800199, 'path' => 'delete', 'size' => 1],
					['version' => 1800100, 'path' => 'delete', 'size' => 1],
					['version' => 1198300, 'path' => 'keep', 'size' => 1],
				],
				16 // size of all deleted files (every file has the size 1)
			],
			// second set of versions, here we have only really old versions
			[
				[
					// first slice (10sec) keep one version every 2 seconds
					// next slice (60sec) starts at 4999990 keep one version every 10 secons
					// next slice (3600sec) start at 4999940 keep one version every 60 seconds
					// next slice (86400sec) start at 4996400 keep one version every 3600 seconds
					['version' => 4996400, 'path' => 'keep', 'size' => 1],
					['version' => 4996350, 'path' => 'delete', 'size' => 1],
					['version' => 4996350, 'path' => 'delete', 'size' => 1],
					['version' => 4992800, 'path' => 'keep', 'size' => 1],
					['version' => 4989800, 'path' => 'delete', 'size' => 1],
					['version' => 4989700, 'path' => 'delete', 'size' => 1],
					['version' => 4989200, 'path' => 'keep', 'size' => 1],
					// next slice (2592000sec) start at 4913600 keep one version every 86400 seconds
					['version' => 4913600, 'path' => 'keep', 'size' => 1],
					['version' => 4852800, 'path' => 'delete', 'size' => 1],
					['version' => 4827201, 'path' => 'delete', 'size' => 1],
					['version' => 4827200, 'path' => 'keep', 'size' => 1],
					['version' => 4777201, 'path' => 'delete', 'size' => 1],
					['version' => 4777501, 'path' => 'delete', 'size' => 1],
					['version' => 4740000, 'path' => 'keep', 'size' => 1],
					// final slice starts at 2408000 keep one version every 604800 secons
					['version' => 2408000, 'path' => 'keep', 'size' => 1],
					['version' => 1803201, 'path' => 'delete', 'size' => 1],
					['version' => 1803200, 'path' => 'keep', 'size' => 1],
					['version' => 1800199, 'path' => 'delete', 'size' => 1],
					['version' => 1800100, 'path' => 'delete', 'size' => 1],
					['version' => 1198300, 'path' => 'keep', 'size' => 1],
				],
				11 // size of all deleted files (every file has the size 1)
			],
			// third set of versions, with some gaps between
			[
				[
					// first slice (10sec) keep one version every 2 seconds
					['version' => 4999999, 'path' => 'keep', 'size' => 1],
					['version' => 4999998, 'path' => 'delete', 'size' => 1],
					['version' => 4999997, 'path' => 'keep', 'size' => 1],
					['version' => 4999995, 'path' => 'keep', 'size' => 1],
					['version' => 4999994, 'path' => 'delete', 'size' => 1],
					//next slice (60sec) starts at 4999990 keep one version every 10 secons
					['version' => 4999988, 'path' => 'keep', 'size' => 1],
					['version' => 4999978, 'path' => 'keep', 'size' => 1],
					//next slice (3600sec) start at 4999940 keep one version every 60 seconds
					// next slice (86400sec) start at 4996400 keep one version every 3600 seconds
					['version' => 4989200, 'path' => 'keep', 'size' => 1],
					// next slice (2592000sec) start at 4913600 keep one version every 86400 seconds
					['version' => 4913600, 'path' => 'keep', 'size' => 1],
					['version' => 4852800, 'path' => 'delete', 'size' => 1],
					['version' => 4827201, 'path' => 'delete', 'size' => 1],
					['version' => 4827200, 'path' => 'keep', 'size' => 1],
					['version' => 4777201, 'path' => 'delete', 'size' => 1],
					['version' => 4777501, 'path' => 'delete', 'size' => 1],
					['version' => 4740000, 'path' => 'keep', 'size' => 1],
					// final slice starts at 2408000 keep one version every 604800 secons
					['version' => 2408000, 'path' => 'keep', 'size' => 1],
					['version' => 1803201, 'path' => 'delete', 'size' => 1],
					['version' => 1803200, 'path' => 'keep', 'size' => 1],
					['version' => 1800199, 'path' => 'delete', 'size' => 1],
					['version' => 1800100, 'path' => 'delete', 'size' => 1],
					['version' => 1198300, 'path' => 'keep', 'size' => 1],
				],
				9 // size of all deleted files (every file has the size 1)
			],
			// fourth set of versions: empty (see issue #19066)
			[
				[],
				0
			]

		];
	}

	public function testRename(): void {
		Filesystem::file_put_contents('test.txt', 'test file');

		$t1 = time();
		// second version is two weeks older, this way we make sure that no
		// version will be expired
		$t2 = $t1 - 60 * 60 * 24 * 14;

		// create some versions
		$v1 = self::USERS_VERSIONS_ROOT . '/test.txt.v' . $t1;
		$v2 = self::USERS_VERSIONS_ROOT . '/test.txt.v' . $t2;
		$v1Renamed = self::USERS_VERSIONS_ROOT . '/test2.txt.v' . $t1;
		$v2Renamed = self::USERS_VERSIONS_ROOT . '/test2.txt.v' . $t2;

		$this->rootView->file_put_contents($v1, 'version1');
		$this->rootView->file_put_contents($v2, 'version2');

		// execute rename hook of versions app
		Filesystem::rename('test.txt', 'test2.txt');

		$this->runCommands();

		$this->assertFalse($this->rootView->file_exists($v1), 'version 1 of old file does not exist');
		$this->assertFalse($this->rootView->file_exists($v2), 'version 2 of old file does not exist');

		$this->assertTrue($this->rootView->file_exists($v1Renamed), 'version 1 of renamed file exists');
		$this->assertTrue($this->rootView->file_exists($v2Renamed), 'version 2 of renamed file exists');
	}

	public function testRenameInSharedFolder(): void {
		Filesystem::mkdir('folder1');
		Filesystem::mkdir('folder1/folder2');
		Filesystem::file_put_contents('folder1/test.txt', 'test file');

		$t1 = time();
		// second version is two weeks older, this way we make sure that no
		// version will be expired
		$t2 = $t1 - 60 * 60 * 24 * 14;

		$this->rootView->mkdir(self::USERS_VERSIONS_ROOT . '/folder1');
		// create some versions
		$v1 = self::USERS_VERSIONS_ROOT . '/folder1/test.txt.v' . $t1;
		$v2 = self::USERS_VERSIONS_ROOT . '/folder1/test.txt.v' . $t2;
		$v1Renamed = self::USERS_VERSIONS_ROOT . '/folder1/folder2/test.txt.v' . $t1;
		$v2Renamed = self::USERS_VERSIONS_ROOT . '/folder1/folder2/test.txt.v' . $t2;

		$this->rootView->file_put_contents($v1, 'version1');
		$this->rootView->file_put_contents($v2, 'version2');

		$node = \OC::$server->getUserFolder(self::TEST_VERSIONS_USER)->get('folder1');
		$share = Server::get(\OCP\Share\IManager::class)->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedBy(self::TEST_VERSIONS_USER)
			->setSharedWith(self::TEST_VERSIONS_USER2)
			->setPermissions(Constants::PERMISSION_ALL);
		$share = Server::get(\OCP\Share\IManager::class)->createShare($share);
		Server::get(\OCP\Share\IManager::class)->acceptShare($share, self::TEST_VERSIONS_USER2);

		self::loginHelper(self::TEST_VERSIONS_USER2);

		$this->assertTrue(Filesystem::file_exists('folder1/test.txt'));

		// execute rename hook of versions app
		Filesystem::rename('/folder1/test.txt', '/folder1/folder2/test.txt');

		$this->runCommands();

		self::loginHelper(self::TEST_VERSIONS_USER);

		$this->assertFalse($this->rootView->file_exists($v1), 'version 1 of old file does not exist');
		$this->assertFalse($this->rootView->file_exists($v2), 'version 2 of old file does not exist');

		$this->assertTrue($this->rootView->file_exists($v1Renamed), 'version 1 of renamed file exists');
		$this->assertTrue($this->rootView->file_exists($v2Renamed), 'version 2 of renamed file exists');

		Server::get(\OCP\Share\IManager::class)->deleteShare($share);
	}

	public function testMoveFolder(): void {
		Filesystem::mkdir('folder1');
		Filesystem::mkdir('folder2');
		Filesystem::file_put_contents('folder1/test.txt', 'test file');

		$t1 = time();
		// second version is two weeks older, this way we make sure that no
		// version will be expired
		$t2 = $t1 - 60 * 60 * 24 * 14;

		// create some versions
		$this->rootView->mkdir(self::USERS_VERSIONS_ROOT . '/folder1');
		$v1 = self::USERS_VERSIONS_ROOT . '/folder1/test.txt.v' . $t1;
		$v2 = self::USERS_VERSIONS_ROOT . '/folder1/test.txt.v' . $t2;
		$v1Renamed = self::USERS_VERSIONS_ROOT . '/folder2/folder1/test.txt.v' . $t1;
		$v2Renamed = self::USERS_VERSIONS_ROOT . '/folder2/folder1/test.txt.v' . $t2;

		$this->rootView->file_put_contents($v1, 'version1');
		$this->rootView->file_put_contents($v2, 'version2');

		// execute rename hook of versions app
		Filesystem::rename('folder1', 'folder2/folder1');

		$this->runCommands();

		$this->assertFalse($this->rootView->file_exists($v1));
		$this->assertFalse($this->rootView->file_exists($v2));

		$this->assertTrue($this->rootView->file_exists($v1Renamed));
		$this->assertTrue($this->rootView->file_exists($v2Renamed));
	}


	public function testMoveFileIntoSharedFolderAsRecipient(): void {
		Filesystem::mkdir('folder1');
		$fileInfo = Filesystem::getFileInfo('folder1');

		$node = \OC::$server->getUserFolder(self::TEST_VERSIONS_USER)->get('folder1');
		$share = Server::get(\OCP\Share\IManager::class)->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedBy(self::TEST_VERSIONS_USER)
			->setSharedWith(self::TEST_VERSIONS_USER2)
			->setPermissions(Constants::PERMISSION_ALL);
		$share = Server::get(\OCP\Share\IManager::class)->createShare($share);
		Server::get(\OCP\Share\IManager::class)->acceptShare($share, self::TEST_VERSIONS_USER2);

		self::loginHelper(self::TEST_VERSIONS_USER2);
		$versionsFolder2 = '/' . self::TEST_VERSIONS_USER2 . '/files_versions';
		Filesystem::file_put_contents('test.txt', 'test file');

		$t1 = time();
		// second version is two weeks older, this way we make sure that no
		// version will be expired
		$t2 = $t1 - 60 * 60 * 24 * 14;

		$this->rootView->mkdir($versionsFolder2);
		// create some versions
		$v1 = $versionsFolder2 . '/test.txt.v' . $t1;
		$v2 = $versionsFolder2 . '/test.txt.v' . $t2;

		$this->rootView->file_put_contents($v1, 'version1');
		$this->rootView->file_put_contents($v2, 'version2');

		// move file into the shared folder as recipient
		$success = Filesystem::rename('/test.txt', '/folder1/test.txt');

		$this->assertTrue($success);
		$this->assertFalse($this->rootView->file_exists($v1));
		$this->assertFalse($this->rootView->file_exists($v2));

		self::loginHelper(self::TEST_VERSIONS_USER);

		$versionsFolder1 = '/' . self::TEST_VERSIONS_USER . '/files_versions';

		$v1Renamed = $versionsFolder1 . '/folder1/test.txt.v' . $t1;
		$v2Renamed = $versionsFolder1 . '/folder1/test.txt.v' . $t2;

		$this->assertTrue($this->rootView->file_exists($v1Renamed));
		$this->assertTrue($this->rootView->file_exists($v2Renamed));

		Server::get(\OCP\Share\IManager::class)->deleteShare($share);
	}

	public function testMoveFolderIntoSharedFolderAsRecipient(): void {
		Filesystem::mkdir('folder1');

		$node = \OC::$server->getUserFolder(self::TEST_VERSIONS_USER)->get('folder1');
		$share = Server::get(\OCP\Share\IManager::class)->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedBy(self::TEST_VERSIONS_USER)
			->setSharedWith(self::TEST_VERSIONS_USER2)
			->setPermissions(Constants::PERMISSION_ALL);
		$share = Server::get(\OCP\Share\IManager::class)->createShare($share);
		Server::get(\OCP\Share\IManager::class)->acceptShare($share, self::TEST_VERSIONS_USER2);

		self::loginHelper(self::TEST_VERSIONS_USER2);
		$versionsFolder2 = '/' . self::TEST_VERSIONS_USER2 . '/files_versions';
		Filesystem::mkdir('folder2');
		Filesystem::file_put_contents('folder2/test.txt', 'test file');

		$t1 = time();
		// second version is two weeks older, this way we make sure that no
		// version will be expired
		$t2 = $t1 - 60 * 60 * 24 * 14;

		$this->rootView->mkdir($versionsFolder2);
		$this->rootView->mkdir($versionsFolder2 . '/folder2');
		// create some versions
		$v1 = $versionsFolder2 . '/folder2/test.txt.v' . $t1;
		$v2 = $versionsFolder2 . '/folder2/test.txt.v' . $t2;

		$this->rootView->file_put_contents($v1, 'version1');
		$this->rootView->file_put_contents($v2, 'version2');

		// move file into the shared folder as recipient
		Filesystem::rename('/folder2', '/folder1/folder2');

		$this->assertFalse($this->rootView->file_exists($v1));
		$this->assertFalse($this->rootView->file_exists($v2));

		self::loginHelper(self::TEST_VERSIONS_USER);

		$versionsFolder1 = '/' . self::TEST_VERSIONS_USER . '/files_versions';

		$v1Renamed = $versionsFolder1 . '/folder1/folder2/test.txt.v' . $t1;
		$v2Renamed = $versionsFolder1 . '/folder1/folder2/test.txt.v' . $t2;

		$this->assertTrue($this->rootView->file_exists($v1Renamed));
		$this->assertTrue($this->rootView->file_exists($v2Renamed));

		Server::get(\OCP\Share\IManager::class)->deleteShare($share);
	}

	public function testRenameSharedFile(): void {
		Filesystem::file_put_contents('test.txt', 'test file');

		$t1 = time();
		// second version is two weeks older, this way we make sure that no
		// version will be expired
		$t2 = $t1 - 60 * 60 * 24 * 14;

		$this->rootView->mkdir(self::USERS_VERSIONS_ROOT);
		// create some versions
		$v1 = self::USERS_VERSIONS_ROOT . '/test.txt.v' . $t1;
		$v2 = self::USERS_VERSIONS_ROOT . '/test.txt.v' . $t2;
		// the renamed versions should not exist! Because we only moved the mount point!
		$v1Renamed = self::USERS_VERSIONS_ROOT . '/test2.txt.v' . $t1;
		$v2Renamed = self::USERS_VERSIONS_ROOT . '/test2.txt.v' . $t2;

		$this->rootView->file_put_contents($v1, 'version1');
		$this->rootView->file_put_contents($v2, 'version2');

		$node = \OC::$server->getUserFolder(self::TEST_VERSIONS_USER)->get('test.txt');
		$share = Server::get(\OCP\Share\IManager::class)->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedBy(self::TEST_VERSIONS_USER)
			->setSharedWith(self::TEST_VERSIONS_USER2)
			->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_SHARE);
		$share = Server::get(\OCP\Share\IManager::class)->createShare($share);
		Server::get(\OCP\Share\IManager::class)->acceptShare($share, self::TEST_VERSIONS_USER2);

		self::loginHelper(self::TEST_VERSIONS_USER2);

		$this->assertTrue(Filesystem::file_exists('test.txt'));

		// execute rename hook of versions app
		Filesystem::rename('test.txt', 'test2.txt');

		self::loginHelper(self::TEST_VERSIONS_USER);

		$this->runCommands();

		$this->assertTrue($this->rootView->file_exists($v1));
		$this->assertTrue($this->rootView->file_exists($v2));

		$this->assertFalse($this->rootView->file_exists($v1Renamed));
		$this->assertFalse($this->rootView->file_exists($v2Renamed));

		Server::get(\OCP\Share\IManager::class)->deleteShare($share);
	}

	public function testCopy(): void {
		Filesystem::file_put_contents('test.txt', 'test file');

		$t1 = time();
		// second version is two weeks older, this way we make sure that no
		// version will be expired
		$t2 = $t1 - 60 * 60 * 24 * 14;

		// create some versions
		$v1 = self::USERS_VERSIONS_ROOT . '/test.txt.v' . $t1;
		$v2 = self::USERS_VERSIONS_ROOT . '/test.txt.v' . $t2;
		$v1Copied = self::USERS_VERSIONS_ROOT . '/test2.txt.v' . $t1;
		$v2Copied = self::USERS_VERSIONS_ROOT . '/test2.txt.v' . $t2;

		$this->rootView->file_put_contents($v1, 'version1');
		$this->rootView->file_put_contents($v2, 'version2');

		// execute copy hook of versions app
		Filesystem::copy('test.txt', 'test2.txt');

		$this->runCommands();

		$this->assertTrue($this->rootView->file_exists($v1), 'version 1 of original file exists');
		$this->assertTrue($this->rootView->file_exists($v2), 'version 2 of original file exists');

		$this->assertTrue($this->rootView->file_exists($v1Copied), 'version 1 of copied file exists');
		$this->assertTrue($this->rootView->file_exists($v2Copied), 'version 2 of copied file exists');
	}

	/**
	 * test if we find all versions and if the versions array contain
	 * the correct 'path' and 'name'
	 */
	public function testGetVersions(): void {
		$t1 = time();
		// second version is two weeks older, this way we make sure that no
		// version will be expired
		$t2 = $t1 - 60 * 60 * 24 * 14;

		// create some versions
		$v1 = self::USERS_VERSIONS_ROOT . '/subfolder/test.txt.v' . $t1;
		$v2 = self::USERS_VERSIONS_ROOT . '/subfolder/test.txt.v' . $t2;

		$this->rootView->mkdir(self::USERS_VERSIONS_ROOT . '/subfolder/');

		$this->rootView->file_put_contents($v1, 'version1');
		$this->rootView->file_put_contents($v2, 'version2');

		// execute copy hook of versions app
		$versions = Storage::getVersions(self::TEST_VERSIONS_USER, '/subfolder/test.txt');

		$this->assertCount(2, $versions);

		foreach ($versions as $version) {
			$this->assertSame('/subfolder/test.txt', $version['path']);
			$this->assertSame('test.txt', $version['name']);
		}

		//cleanup
		$this->rootView->deleteAll(self::USERS_VERSIONS_ROOT . '/subfolder');
	}

	/**
	 * test if we find all versions and if the versions array contain
	 * the correct 'path' and 'name'
	 */
	public function testGetVersionsEmptyFile(): void {
		// execute copy hook of versions app
		$versions = Storage::getVersions(self::TEST_VERSIONS_USER, '');
		$this->assertCount(0, $versions);

		$versions = Storage::getVersions(self::TEST_VERSIONS_USER, null);
		$this->assertCount(0, $versions);
	}

	public function testExpireNonexistingFile(): void {
		$this->logout();
		// needed to have a FS setup (the background job does this)
		\OC_Util::setupFS(self::TEST_VERSIONS_USER);

		$this->assertFalse(Storage::expire('/void/unexist.txt', self::TEST_VERSIONS_USER));
	}


	public function testExpireNonexistingUser(): void {
		$this->expectException(NoUserException::class);

		$this->logout();
		// needed to have a FS setup (the background job does this)
		\OC_Util::setupFS(self::TEST_VERSIONS_USER);
		Filesystem::file_put_contents('test.txt', 'test file');

		$this->assertFalse(Storage::expire('test.txt', 'unexist'));
	}

	public function testRestoreSameStorage(): void {
		Filesystem::mkdir('sub');
		$this->doTestRestore();
	}

	public function testRestoreCrossStorage(): void {
		$storage2 = new Temporary([]);
		Filesystem::mount($storage2, [], self::TEST_VERSIONS_USER . '/files/sub');

		$this->doTestRestore();
	}

	public function testRestoreNoPermission(): void {
		$this->loginAsUser(self::TEST_VERSIONS_USER);

		$userHome = \OC::$server->getUserFolder(self::TEST_VERSIONS_USER);
		$node = $userHome->newFolder('folder');
		$file = $node->newFile('test.txt');

		$share = Server::get(\OCP\Share\IManager::class)->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedBy(self::TEST_VERSIONS_USER)
			->setSharedWith(self::TEST_VERSIONS_USER2)
			->setPermissions(Constants::PERMISSION_READ);
		$share = Server::get(\OCP\Share\IManager::class)->createShare($share);
		Server::get(\OCP\Share\IManager::class)->acceptShare($share, self::TEST_VERSIONS_USER2);

		$versions = $this->createAndCheckVersions(
			Filesystem::getView(),
			'folder/test.txt'
		);

		$file->putContent('test file');

		$this->loginAsUser(self::TEST_VERSIONS_USER2);

		$firstVersion = current($versions);

		$this->assertFalse(Storage::rollback('folder/test.txt', (int)$firstVersion['version'], $this->user2), 'Revert did not happen');

		$this->loginAsUser(self::TEST_VERSIONS_USER);

		Server::get(\OCP\Share\IManager::class)->deleteShare($share);
		$this->assertEquals('test file', $file->getContent(), 'File content has not changed');
	}

	public function testRestoreMovedShare(): void {
		$this->markTestSkipped('Unreliable test');
		$this->loginAsUser(self::TEST_VERSIONS_USER);

		$userHome = \OC::$server->getUserFolder(self::TEST_VERSIONS_USER);
		$node = $userHome->newFolder('folder');
		$file = $node->newFile('test.txt');

		$userHome2 = \OC::$server->getUserFolder(self::TEST_VERSIONS_USER2);
		$userHome2->newFolder('subfolder');

		$share = Server::get(\OCP\Share\IManager::class)->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedBy(self::TEST_VERSIONS_USER)
			->setSharedWith(self::TEST_VERSIONS_USER2)
			->setPermissions(Constants::PERMISSION_ALL);
		$share = Server::get(\OCP\Share\IManager::class)->createShare($share);
		$shareManager = Server::get(\OCP\Share\IManager::class);
		$shareManager->acceptShare($share, self::TEST_VERSIONS_USER2);

		$share->setTarget('subfolder/folder');
		$shareManager->moveShare($share, self::TEST_VERSIONS_USER2);

		$versions = $this->createAndCheckVersions(
			Filesystem::getView(),
			'folder/test.txt'
		);

		$file->putContent('test file');

		$this->loginAsUser(self::TEST_VERSIONS_USER2);

		$firstVersion = current($versions);

		$this->assertTrue(Storage::rollback('folder/test.txt', $firstVersion['version'], $this->user1));

		$this->loginAsUser(self::TEST_VERSIONS_USER);

		Server::get(\OCP\Share\IManager::class)->deleteShare($share);
		$this->assertEquals('version 2', $file->getContent(), 'File content has not changed');
	}

	/**
	 * @param string $hookName name of hook called
	 * @param string $params variable to receive parameters provided by hook
	 */
	private function connectMockHooks($hookName, &$params) {
		if ($hookName === null) {
			return;
		}

		$eventHandler = $this->getMockBuilder(DummyHookListener::class)
			->onlyMethods(['callback'])
			->getMock();

		$eventHandler->expects($this->any())
			->method('callback')
			->willReturnCallback(
				function ($p) use (&$params): void {
					$params = $p;
				}
			);

		Util::connectHook(
			'\OCP\Versions',
			$hookName,
			$eventHandler,
			'callback'
		);
	}

	private function doTestRestore(): void {
		$filePath = self::TEST_VERSIONS_USER . '/files/sub/test.txt';
		$this->rootView->file_put_contents($filePath, 'test file');

		$fileInfo = $this->rootView->getFileInfo($filePath);
		$t0 = $this->rootView->filemtime($filePath);

		// not exactly the same timestamp as the file
		$t1 = time() - 60;
		// second version is two weeks older
		$t2 = $t1 - 60 * 60 * 24 * 14;

		// create some versions
		$v1 = self::USERS_VERSIONS_ROOT . '/sub/test.txt.v' . $t1;
		$v2 = self::USERS_VERSIONS_ROOT . '/sub/test.txt.v' . $t2;

		$this->rootView->mkdir(self::USERS_VERSIONS_ROOT . '/sub');

		$this->rootView->file_put_contents($v1, 'version1');
		$fileInfoV1 = $this->rootView->getFileInfo($v1);
		$versionEntity = new VersionEntity();
		$versionEntity->setFileId($fileInfo->getId());
		$versionEntity->setTimestamp($t1);
		$versionEntity->setSize($fileInfoV1->getSize());
		$versionEntity->setMimetype($this->mimeTypeLoader->getId($fileInfoV1->getMimetype()));
		$versionEntity->setMetadata([]);
		$this->versionsMapper->insert($versionEntity);

		$this->rootView->file_put_contents($v2, 'version2');
		$fileInfoV2 = $this->rootView->getFileInfo($v2);
		$versionEntity = new VersionEntity();
		$versionEntity->setFileId($fileInfo->getId());
		$versionEntity->setTimestamp($t2);
		$versionEntity->setSize($fileInfoV2->getSize());
		$versionEntity->setMimetype($this->mimeTypeLoader->getId($fileInfoV2->getMimetype()));
		$versionEntity->setMetadata([]);
		$this->versionsMapper->insert($versionEntity);

		$oldVersions = Storage::getVersions(
			self::TEST_VERSIONS_USER, '/sub/test.txt'
		);

		$this->assertCount(2, $oldVersions);

		$this->assertEquals('test file', $this->rootView->file_get_contents($filePath));
		$info1 = $this->rootView->getFileInfo($filePath);

		$eventDispatcher = Server::get(IEventDispatcher::class);
		$eventFired = false;
		$eventDispatcher->addListener(VersionRestoredEvent::class, function ($event) use (&$eventFired, $t2): void {
			$eventFired = true;
			$this->assertEquals('/sub/test.txt', $event->getVersion()->getVersionPath());
			$this->assertTrue($event->getVersion()->getRevisionId() > 0);
		});

		$versionManager = Server::get(IVersionManager::class);
		$versions = $versionManager->getVersionsForFile($this->user1, $info1);
		$version = array_filter($versions, function ($version) use ($t2) {
			return $version->getRevisionId() === $t2;
		});
		$this->assertTrue($versionManager->rollback(current($version)));

		$this->assertTrue($eventFired, 'VersionRestoredEvent was not fired');

		$this->assertEquals('version2', $this->rootView->file_get_contents($filePath));
		$info2 = $this->rootView->getFileInfo($filePath);

		$this->assertNotEquals(
			$info2['etag'],
			$info1['etag'],
			'Etag must change after rolling back version'
		);
		$this->assertEquals(
			$info2['fileid'],
			$info1['fileid'],
			'File id must not change after rolling back version'
		);
		$this->assertEquals(
			$info2['mtime'],
			$t2,
			'Restored file has mtime from version'
		);

		$newVersions = Storage::getVersions(
			self::TEST_VERSIONS_USER, '/sub/test.txt'
		);

		$this->assertTrue(
			$this->rootView->file_exists(self::USERS_VERSIONS_ROOT . '/sub/test.txt.v' . $t0),
			'A version file was created for the file before restoration'
		);
		$this->assertTrue(
			$this->rootView->file_exists($v1),
			'Untouched version file is still there'
		);
		$this->assertFalse(
			$this->rootView->file_exists($v2),
			'Restored version file gone from files_version folder'
		);

		$this->assertCount(2, $newVersions, 'Additional version created');

		$this->assertTrue(
			isset($newVersions[$t0 . '#' . 'test.txt']),
			'A version was created for the file before restoration'
		);
		$this->assertTrue(
			isset($newVersions[$t1 . '#' . 'test.txt']),
			'Untouched version is still there'
		);
		$this->assertFalse(
			isset($newVersions[$t2 . '#' . 'test.txt']),
			'Restored version is not in the list any more'
		);
	}

	/**
	 * Test whether versions are created when overwriting as owner
	 */
	public function testStoreVersionAsOwner(): void {
		$this->loginAsUser(self::TEST_VERSIONS_USER);

		$this->createAndCheckVersions(
			Filesystem::getView(),
			'test.txt'
		);
	}

	/**
	 * Test whether versions are created when overwriting as share recipient
	 */
	public function testStoreVersionAsRecipient(): void {
		$this->loginAsUser(self::TEST_VERSIONS_USER);

		Filesystem::mkdir('folder');
		Filesystem::file_put_contents('folder/test.txt', 'test file');

		$node = \OC::$server->getUserFolder(self::TEST_VERSIONS_USER)->get('folder');
		$share = Server::get(\OCP\Share\IManager::class)->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedBy(self::TEST_VERSIONS_USER)
			->setSharedWith(self::TEST_VERSIONS_USER2)
			->setPermissions(Constants::PERMISSION_ALL);
		$share = Server::get(\OCP\Share\IManager::class)->createShare($share);
		Server::get(\OCP\Share\IManager::class)->acceptShare($share, self::TEST_VERSIONS_USER2);

		$this->loginAsUser(self::TEST_VERSIONS_USER2);

		$this->createAndCheckVersions(
			Filesystem::getView(),
			'folder/test.txt'
		);

		Server::get(\OCP\Share\IManager::class)->deleteShare($share);
	}

	/**
	 * Test whether versions are created when overwriting anonymously.
	 *
	 * When uploading through a public link or publicwebdav, no user
	 * is logged in. File modification must still be able to find
	 * the owner and create versions.
	 */
	public function testStoreVersionAsAnonymous(): void {
		$this->logout();

		// note: public link upload does this,
		// needed to make the hooks fire
		\OC_Util::setupFS(self::TEST_VERSIONS_USER);

		$userView = new View('/' . self::TEST_VERSIONS_USER . '/files');
		$this->createAndCheckVersions(
			$userView,
			'test.txt'
		);
	}

	private function createAndCheckVersions(View $view, string $path): array {
		$view->file_put_contents($path, 'test file');
		$view->file_put_contents($path, 'version 1');
		$view->file_put_contents($path, 'version 2');

		$this->loginAsUser(self::TEST_VERSIONS_USER);

		// need to scan for the versions
		[$rootStorage,] = $this->rootView->resolvePath(self::TEST_VERSIONS_USER . '/files_versions');
		$rootStorage->getScanner()->scan('files_versions');

		$versions = Storage::getVersions(
			self::TEST_VERSIONS_USER, '/' . $path
		);

		// note: we cannot predict how many versions are created due to
		// test run timing
		$this->assertGreaterThan(0, count($versions));

		return $versions;
	}

	public static function loginHelper(string $user, bool $create = false) {
		if ($create) {
			$backend = new \Test\Util\User\Dummy();
			$backend->createUser($user, $user);
			Server::get(IUserManager::class)->registerBackend($backend);
		}

		\OC_Util::tearDownFS();
		\OC_User::setUserId('');
		Filesystem::tearDown();
		\OC_User::setUserId($user);
		\OC_Util::setupFS($user);
		\OC::$server->getUserFolder($user);
	}
}

class DummyHookListener {
	public function callback() {
	}
}

// extend the original class to make it possible to test protected methods
class VersionStorageToTest extends Storage {

	/**
	 * @param integer $time
	 */
	public function callProtectedGetExpireList($time, $versions) {
		return self::getExpireList($time, $versions);
	}
}
