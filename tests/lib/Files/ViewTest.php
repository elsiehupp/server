<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Test\Files;

use OC\Files\Cache\Scanner;
use OC\Files\Cache\Watcher;
use OC\Files\Filesystem;
use OC\Files\Mount\MountPoint;
use OC\Files\SetupManager;
use OC\Files\Storage\Common;
use OC\Files\Storage\Storage;
use OC\Files\Storage\Temporary;
use OC\Files\View;
use OC\Share20\ShareDisableChecker;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCP\Cache\CappedMemoryCache;
use OCP\Constants;
use OCP\Files\Config\IMountProvider;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Files\FileInfo;
use OCP\Files\ForbiddenException;
use OCP\Files\GenericFileException;
use OCP\Files\InvalidPathException;
use OCP\Files\Mount\IMountManager;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\Files\Storage\IStorageFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\Util;
use PHPUnit\Framework\MockObject\MockObject;
use Test\HookHelper;
use Test\TestMoveableMountPoint;
use Test\Traits\UserTrait;

class TemporaryNoTouch extends Temporary {
	public function touch(string $path, ?int $mtime = null): bool {
		return false;
	}
}

class TemporaryNoCross extends Temporary {
	public function copyFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath, bool $preserveMtime = false): bool {
		return Common::copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath, $preserveMtime);
	}

	public function moveFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
		return Common::moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
	}
}

class TemporaryNoLocal extends Temporary {
	public function instanceOfStorage(string $class): bool {
		if ($class === '\OC\Files\Storage\Local') {
			return false;
		} else {
			return parent::instanceOfStorage($class);
		}
	}
}

class TestEventHandler {
	public function umount() {
	}
	public function post_umount() {
	}
	public function preCallback() {
	}
	public function postCallback() {
	}
}

/**
 * Class ViewTest
 *
 * @group DB
 *
 * @package Test\Files
 */
class ViewTest extends \Test\TestCase {
	use UserTrait;

	/**
	 * @var Storage[] $storages
	 */
	private $storages = [];

	/**
	 * @var string
	 */
	private $user;

	/**
	 * @var IUser
	 */
	private $userObject;

	/**
	 * @var IGroup
	 */
	private $groupObject;

	/** @var Storage */
	private $tempStorage;

	protected function setUp(): void {
		parent::setUp();
		\OC_Hook::clear();

		Server::get(IUserManager::class)->clearBackends();
		Server::get(IUserManager::class)->registerBackend(new \Test\Util\User\Dummy());

		//login
		$userManager = Server::get(IUserManager::class);
		$groupManager = Server::get(IGroupManager::class);
		$this->user = 'test';
		$this->userObject = $userManager->createUser('test', 'test');

		$this->groupObject = $groupManager->createGroup('group1');
		$this->groupObject->addUser($this->userObject);

		self::loginAsUser($this->user);

		/** @var IMountManager $manager */
		$manager = Server::get(IMountManager::class);
		$manager->removeMount('/test');

		$this->tempStorage = null;
	}

	protected function tearDown(): void {
		\OC_User::setUserId($this->user);
		foreach ($this->storages as $storage) {
			$cache = $storage->getCache();
			$ids = $cache->getAll();
			$cache->clear();
		}

		if ($this->tempStorage) {
			system('rm -rf ' . escapeshellarg($this->tempStorage->getDataDir()));
		}

		self::logout();

		/** @var SetupManager $setupManager */
		$setupManager = Server::get(SetupManager::class);
		$setupManager->setupRoot();

		$this->userObject->delete();
		$this->groupObject->delete();

		$mountProviderCollection = Server::get(IMountProviderCollection::class);
		self::invokePrivate($mountProviderCollection, 'providers', [[]]);

		parent::tearDown();
	}

	/**
	 * @medium
	 */
	public function testCacheAPI(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$storage3 = $this->getTestStorage();
		$root = self::getUniqueID('/');
		Filesystem::mount($storage1, [], $root . '/');
		Filesystem::mount($storage2, [], $root . '/substorage');
		Filesystem::mount($storage3, [], $root . '/folder/anotherstorage');
		$textSize = strlen("dummy file data\n");
		$imageSize = filesize(\OC::$SERVERROOT . '/core/img/logo/logo.png');
		$storageSize = $textSize * 2 + $imageSize;

		$storageInfo = $storage3->getCache()->get('');
		$this->assertEquals($storageSize, $storageInfo['size']);

		$rootView = new View($root);

		$cachedData = $rootView->getFileInfo('/foo.txt');
		$this->assertEquals($textSize, $cachedData['size']);
		$this->assertEquals('text/plain', $cachedData['mimetype']);
		$this->assertNotEquals(-1, $cachedData['permissions']);

		$cachedData = $rootView->getFileInfo('/');
		$this->assertEquals($storageSize * 3, $cachedData['size']);
		$this->assertEquals('httpd/unix-directory', $cachedData['mimetype']);

		// get cached data excluding mount points
		$cachedData = $rootView->getFileInfo('/', false);
		$this->assertEquals($storageSize, $cachedData['size']);
		$this->assertEquals('httpd/unix-directory', $cachedData['mimetype']);

		$cachedData = $rootView->getFileInfo('/folder');
		$this->assertEquals($storageSize + $textSize, $cachedData['size']);
		$this->assertEquals('httpd/unix-directory', $cachedData['mimetype']);

		$folderData = $rootView->getDirectoryContent('/');
		/**
		 * expected entries:
		 * folder
		 * foo.png
		 * foo.txt
		 * substorage
		 */
		$this->assertCount(4, $folderData);
		$this->assertEquals('folder', $folderData[0]['name']);
		$this->assertEquals('foo.png', $folderData[1]['name']);
		$this->assertEquals('foo.txt', $folderData[2]['name']);
		$this->assertEquals('substorage', $folderData[3]['name']);

		$this->assertEquals($storageSize + $textSize, $folderData[0]['size']);
		$this->assertEquals($imageSize, $folderData[1]['size']);
		$this->assertEquals($textSize, $folderData[2]['size']);
		$this->assertEquals($storageSize, $folderData[3]['size']);

		$folderData = $rootView->getDirectoryContent('/substorage');
		/**
		 * expected entries:
		 * folder
		 * foo.png
		 * foo.txt
		 */
		$this->assertCount(3, $folderData);
		$this->assertEquals('folder', $folderData[0]['name']);
		$this->assertEquals('foo.png', $folderData[1]['name']);
		$this->assertEquals('foo.txt', $folderData[2]['name']);

		$folderView = new View($root . '/folder');
		$this->assertEquals($rootView->getFileInfo('/folder'), $folderView->getFileInfo('/'));

		$cachedData = $rootView->getFileInfo('/foo.txt');
		$this->assertFalse($cachedData['encrypted']);
		$id = $rootView->putFileInfo('/foo.txt', ['encrypted' => true]);
		$cachedData = $rootView->getFileInfo('/foo.txt');
		$this->assertTrue($cachedData['encrypted']);
		$this->assertEquals($cachedData['fileid'], $id);

		$this->assertFalse($rootView->getFileInfo('/non/existing'));
		$this->assertEquals([], $rootView->getDirectoryContent('/non/existing'));
	}

	/**
	 * @medium
	 */
	public function testGetPath(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$storage3 = $this->getTestStorage();

		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], '/substorage');
		Filesystem::mount($storage3, [], '/folder/anotherstorage');

		$rootView = new View('');


		$cachedData = $rootView->getFileInfo('/foo.txt');
		/** @var int $id1 */
		$id1 = $cachedData['fileid'];
		$this->assertEquals('/foo.txt', $rootView->getPath($id1));

		$cachedData = $rootView->getFileInfo('/substorage/foo.txt');
		/** @var int $id2 */
		$id2 = $cachedData['fileid'];
		$this->assertEquals('/substorage/foo.txt', $rootView->getPath($id2));

		$folderView = new View('/substorage');
		$this->assertEquals('/foo.txt', $folderView->getPath($id2));
	}


	public function testGetPathNotExisting(): void {
		$this->expectException(NotFoundException::class);

		$storage1 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');

		$rootView = new View('');
		$cachedData = $rootView->getFileInfo('/foo.txt');
		/** @var int $id1 */
		$id1 = $cachedData['fileid'];
		$folderView = new View('/substorage');
		$this->assertNull($folderView->getPath($id1));
	}

	/**
	 * @medium
	 */
	public function testMountPointOverwrite(): void {
		$storage1 = $this->getTestStorage(false);
		$storage2 = $this->getTestStorage();
		$storage1->mkdir('substorage');
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], '/substorage');

		$rootView = new View('');
		$folderContent = $rootView->getDirectoryContent('/');
		$this->assertCount(4, $folderContent);
	}

	public static function sharingDisabledPermissionProvider(): array {
		return [
			['no', '', true],
			['yes', 'group1', false],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('sharingDisabledPermissionProvider')]
	public function testRemoveSharePermissionWhenSharingDisabledForUser($excludeGroups, $excludeGroupsList, $expectedShareable): void {
		// Reset sharing disabled for users cache
		self::invokePrivate(Server::get(ShareDisableChecker::class), 'sharingDisabledForUsersCache', [new CappedMemoryCache()]);

		$config = Server::get(IConfig::class);
		$oldExcludeGroupsFlag = $config->getAppValue('core', 'shareapi_exclude_groups', 'no');
		$oldExcludeGroupsList = $config->getAppValue('core', 'shareapi_exclude_groups_list', '');
		$config->setAppValue('core', 'shareapi_exclude_groups', $excludeGroups);
		$config->setAppValue('core', 'shareapi_exclude_groups_list', $excludeGroupsList);

		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], '/mount');

		$view = new View('/');

		$folderContent = $view->getDirectoryContent('');
		$this->assertEquals($expectedShareable, $folderContent[0]->isShareable());

		$folderContent = $view->getDirectoryContent('mount');
		$this->assertEquals($expectedShareable, $folderContent[0]->isShareable());

		$config->setAppValue('core', 'shareapi_exclude_groups', $oldExcludeGroupsFlag);
		$config->setAppValue('core', 'shareapi_exclude_groups_list', $oldExcludeGroupsList);

		// Reset sharing disabled for users cache
		self::invokePrivate(Server::get(ShareDisableChecker::class), 'sharingDisabledForUsersCache', [new CappedMemoryCache()]);
	}

	public function testCacheIncompleteFolder(): void {
		$storage1 = $this->getTestStorage(false);
		Filesystem::mount($storage1, [], '/incomplete');
		$rootView = new View('/incomplete');

		$entries = $rootView->getDirectoryContent('/');
		$this->assertCount(3, $entries);

		// /folder will already be in the cache but not scanned
		$entries = $rootView->getDirectoryContent('/folder');
		$this->assertCount(1, $entries);
	}

	public function testAutoScan(): void {
		$storage1 = $this->getTestStorage(false);
		$storage2 = $this->getTestStorage(false);
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], '/substorage');
		$textSize = strlen("dummy file data\n");

		$rootView = new View('');

		$cachedData = $rootView->getFileInfo('/');
		$this->assertEquals('httpd/unix-directory', $cachedData['mimetype']);
		$this->assertEquals(-1, $cachedData['size']);

		$folderData = $rootView->getDirectoryContent('/substorage/folder');
		$this->assertEquals('text/plain', $folderData[0]['mimetype']);
		$this->assertEquals($textSize, $folderData[0]['size']);
	}

	/**
	 * @medium
	 */
	public function testSearch(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$storage3 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], '/substorage');
		Filesystem::mount($storage3, [], '/folder/anotherstorage');

		$rootView = new View('');

		$results = $rootView->search('foo');
		$this->assertCount(6, $results);
		$paths = [];
		foreach ($results as $result) {
			$this->assertEquals($result['path'], Filesystem::normalizePath($result['path']));
			$paths[] = $result['path'];
		}
		$this->assertContains('/foo.txt', $paths);
		$this->assertContains('/foo.png', $paths);
		$this->assertContains('/substorage/foo.txt', $paths);
		$this->assertContains('/substorage/foo.png', $paths);
		$this->assertContains('/folder/anotherstorage/foo.txt', $paths);
		$this->assertContains('/folder/anotherstorage/foo.png', $paths);

		$folderView = new View('/folder');
		$results = $folderView->search('bar');
		$this->assertCount(2, $results);
		$paths = [];
		foreach ($results as $result) {
			$paths[] = $result['path'];
		}
		$this->assertContains('/anotherstorage/folder/bar.txt', $paths);
		$this->assertContains('/bar.txt', $paths);

		$results = $folderView->search('foo');
		$this->assertCount(2, $results);
		$paths = [];
		foreach ($results as $result) {
			$paths[] = $result['path'];
		}
		$this->assertContains('/anotherstorage/foo.txt', $paths);
		$this->assertContains('/anotherstorage/foo.png', $paths);

		$this->assertCount(6, $rootView->searchByMime('text'));
		$this->assertCount(3, $folderView->searchByMime('text'));
	}

	/**
	 * @medium
	 */
	public function testWatcher(): void {
		$storage1 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');
		$storage1->getWatcher()->setPolicy(Watcher::CHECK_ALWAYS);

		$rootView = new View('');

		$cachedData = $rootView->getFileInfo('foo.txt');
		$this->assertEquals(16, $cachedData['size']);

		$rootView->putFileInfo('foo.txt', ['storage_mtime' => 10]);
		$storage1->file_put_contents('foo.txt', 'foo');
		clearstatcache();

		$cachedData = $rootView->getFileInfo('foo.txt');
		$this->assertEquals(3, $cachedData['size']);
	}

	/**
	 * @medium
	 */
	public function testCopyBetweenStorageNoCross(): void {
		$storage1 = $this->getTestStorage(true, TemporaryNoCross::class);
		$storage2 = $this->getTestStorage(true, TemporaryNoCross::class);
		$this->copyBetweenStorages($storage1, $storage2);
	}

	/**
	 * @medium
	 */
	public function testCopyBetweenStorageCross(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$this->copyBetweenStorages($storage1, $storage2);
	}

	/**
	 * @medium
	 */
	public function testCopyBetweenStorageCrossNonLocal(): void {
		$storage1 = $this->getTestStorage(true, TemporaryNoLocal::class);
		$storage2 = $this->getTestStorage(true, TemporaryNoLocal::class);
		$this->copyBetweenStorages($storage1, $storage2);
	}

	public function copyBetweenStorages($storage1, $storage2) {
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], '/substorage');

		$rootView = new View('');
		$rootView->mkdir('substorage/emptyfolder');
		$rootView->copy('substorage', 'anotherfolder');
		$this->assertTrue($rootView->is_dir('/anotherfolder'));
		$this->assertTrue($rootView->is_dir('/substorage'));
		$this->assertTrue($rootView->is_dir('/anotherfolder/emptyfolder'));
		$this->assertTrue($rootView->is_dir('/substorage/emptyfolder'));
		$this->assertTrue($rootView->file_exists('/anotherfolder/foo.txt'));
		$this->assertTrue($rootView->file_exists('/anotherfolder/foo.png'));
		$this->assertTrue($rootView->file_exists('/anotherfolder/folder/bar.txt'));
		$this->assertTrue($rootView->file_exists('/substorage/foo.txt'));
		$this->assertTrue($rootView->file_exists('/substorage/foo.png'));
		$this->assertTrue($rootView->file_exists('/substorage/folder/bar.txt'));
	}

	/**
	 * @medium
	 */
	public function testMoveBetweenStorageNoCross(): void {
		$storage1 = $this->getTestStorage(true, TemporaryNoCross::class);
		$storage2 = $this->getTestStorage(true, TemporaryNoCross::class);
		$this->moveBetweenStorages($storage1, $storage2);
	}

	/**
	 * @medium
	 */
	public function testMoveBetweenStorageCross(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$this->moveBetweenStorages($storage1, $storage2);
	}

	/**
	 * @medium
	 */
	public function testMoveBetweenStorageCrossNonLocal(): void {
		$storage1 = $this->getTestStorage(true, TemporaryNoLocal::class);
		$storage2 = $this->getTestStorage(true, TemporaryNoLocal::class);
		$this->moveBetweenStorages($storage1, $storage2);
	}

	public function moveBetweenStorages($storage1, $storage2) {
		Filesystem::mount($storage1, [], '/' . $this->user . '/');
		Filesystem::mount($storage2, [], '/' . $this->user . '/substorage');

		$rootView = new View('/' . $this->user);
		$rootView->rename('foo.txt', 'substorage/folder/foo.txt');
		$this->assertFalse($rootView->file_exists('foo.txt'));
		$this->assertTrue($rootView->file_exists('substorage/folder/foo.txt'));
		$rootView->rename('substorage/folder', 'anotherfolder');
		$this->assertFalse($rootView->is_dir('substorage/folder'));
		$this->assertTrue($rootView->file_exists('anotherfolder/foo.txt'));
		$this->assertTrue($rootView->file_exists('anotherfolder/bar.txt'));
	}

	/**
	 * @medium
	 */
	public function testUnlink(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], '/substorage');

		$rootView = new View('');
		$rootView->file_put_contents('/foo.txt', 'asd');
		$rootView->file_put_contents('/substorage/bar.txt', 'asd');

		$this->assertTrue($rootView->file_exists('foo.txt'));
		$this->assertTrue($rootView->file_exists('substorage/bar.txt'));

		$this->assertTrue($rootView->unlink('foo.txt'));
		$this->assertTrue($rootView->unlink('substorage/bar.txt'));

		$this->assertFalse($rootView->file_exists('foo.txt'));
		$this->assertFalse($rootView->file_exists('substorage/bar.txt'));
	}

	public static function rmdirOrUnlinkDataProvider(): array {
		return [['rmdir'], ['unlink']];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('rmdirOrUnlinkDataProvider')]
	public function testRmdir($method): void {
		$storage1 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');

		$rootView = new View('');
		$rootView->mkdir('sub');
		$rootView->mkdir('sub/deep');
		$rootView->file_put_contents('/sub/deep/foo.txt', 'asd');

		$this->assertTrue($rootView->file_exists('sub/deep/foo.txt'));

		$this->assertTrue($rootView->$method('sub'));

		$this->assertFalse($rootView->file_exists('sub'));
	}

	/**
	 * @medium
	 */
	public function testUnlinkRootMustFail(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], '/substorage');

		$rootView = new View('');
		$rootView->file_put_contents('/foo.txt', 'asd');
		$rootView->file_put_contents('/substorage/bar.txt', 'asd');

		$this->assertFalse($rootView->unlink(''));
		$this->assertFalse($rootView->unlink('/'));
		$this->assertFalse($rootView->unlink('substorage'));
		$this->assertFalse($rootView->unlink('/substorage'));
	}

	/**
	 * @medium
	 */
	public function testTouch(): void {
		$storage = $this->getTestStorage(true, TemporaryNoTouch::class);

		Filesystem::mount($storage, [], '/');

		$rootView = new View('');
		$oldCachedData = $rootView->getFileInfo('foo.txt');

		$rootView->touch('foo.txt', 500);

		$cachedData = $rootView->getFileInfo('foo.txt');
		$this->assertEquals(500, $cachedData['mtime']);
		$this->assertEquals($oldCachedData['storage_mtime'], $cachedData['storage_mtime']);

		$rootView->putFileInfo('foo.txt', ['storage_mtime' => 1000]); //make sure the watcher detects the change
		$rootView->file_put_contents('foo.txt', 'asd');
		$cachedData = $rootView->getFileInfo('foo.txt');
		$this->assertGreaterThanOrEqual($oldCachedData['mtime'], $cachedData['mtime']);
		$this->assertEquals($cachedData['storage_mtime'], $cachedData['mtime']);
	}

	/**
	 * @medium
	 */
	public function testTouchFloat(): void {
		$storage = $this->getTestStorage(true, TemporaryNoTouch::class);

		Filesystem::mount($storage, [], '/');

		$rootView = new View('');
		$oldCachedData = $rootView->getFileInfo('foo.txt');

		$rootView->touch('foo.txt', 500.5);

		$cachedData = $rootView->getFileInfo('foo.txt');
		$this->assertEquals(500, $cachedData['mtime']);
	}

	/**
	 * @medium
	 */
	public function testViewHooks(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$defaultRoot = Filesystem::getRoot();
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], $defaultRoot . '/substorage');
		\OC_Hook::connect('OC_Filesystem', 'post_write', $this, 'dummyHook');

		$rootView = new View('');
		$subView = new View($defaultRoot . '/substorage');
		$this->hookPath = null;

		$rootView->file_put_contents('/foo.txt', 'asd');
		$this->assertNull($this->hookPath);

		$subView->file_put_contents('/foo.txt', 'asd');
		$this->assertEquals('/substorage/foo.txt', $this->hookPath);
	}

	private $hookPath;

	public function dummyHook($params) {
		$this->hookPath = $params['path'];
	}

	public function testSearchNotOutsideView(): void {
		$storage1 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');
		$storage1->rename('folder', 'foo');
		$scanner = $storage1->getScanner();
		$scanner->scan('');

		$view = new View('/foo');

		$result = $view->search('.txt');
		$this->assertCount(1, $result);
	}

	/**
	 * @param bool $scan
	 * @param string $class
	 * @return Storage
	 */
	private function getTestStorage($scan = true, $class = Temporary::class) {
		/**
		 * @var Storage $storage
		 */
		$storage = new $class([]);
		$textData = "dummy file data\n";
		$imgData = file_get_contents(\OC::$SERVERROOT . '/core/img/logo/logo.png');
		$storage->mkdir('folder');
		$storage->file_put_contents('foo.txt', $textData);
		$storage->file_put_contents('foo.png', $imgData);
		$storage->file_put_contents('folder/bar.txt', $textData);

		if ($scan) {
			$scanner = $storage->getScanner();
			$scanner->scan('');
		}
		$this->storages[] = $storage;
		return $storage;
	}

	/**
	 * @medium
	 */
	public function testViewHooksIfRootStartsTheSame(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$defaultRoot = Filesystem::getRoot();
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], $defaultRoot . '_substorage');
		\OC_Hook::connect('OC_Filesystem', 'post_write', $this, 'dummyHook');

		$subView = new View($defaultRoot . '_substorage');
		$this->hookPath = null;

		$subView->file_put_contents('/foo.txt', 'asd');
		$this->assertNull($this->hookPath);
	}

	private $hookWritePath;
	private $hookCreatePath;
	private $hookUpdatePath;

	public function dummyHookWrite($params) {
		$this->hookWritePath = $params['path'];
	}

	public function dummyHookUpdate($params) {
		$this->hookUpdatePath = $params['path'];
	}

	public function dummyHookCreate($params) {
		$this->hookCreatePath = $params['path'];
	}

	public function testEditNoCreateHook(): void {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$defaultRoot = Filesystem::getRoot();
		Filesystem::mount($storage1, [], '/');
		Filesystem::mount($storage2, [], $defaultRoot);
		\OC_Hook::connect('OC_Filesystem', 'post_create', $this, 'dummyHookCreate');
		\OC_Hook::connect('OC_Filesystem', 'post_update', $this, 'dummyHookUpdate');
		\OC_Hook::connect('OC_Filesystem', 'post_write', $this, 'dummyHookWrite');

		$view = new View($defaultRoot);
		$this->hookWritePath = $this->hookUpdatePath = $this->hookCreatePath = null;

		$view->file_put_contents('/asd.txt', 'foo');
		$this->assertEquals('/asd.txt', $this->hookCreatePath);
		$this->assertNull($this->hookUpdatePath);
		$this->assertEquals('/asd.txt', $this->hookWritePath);

		$this->hookWritePath = $this->hookUpdatePath = $this->hookCreatePath = null;

		$view->file_put_contents('/asd.txt', 'foo');
		$this->assertNull($this->hookCreatePath);
		$this->assertEquals('/asd.txt', $this->hookUpdatePath);
		$this->assertEquals('/asd.txt', $this->hookWritePath);

		\OC_Hook::clear('OC_Filesystem', 'post_create');
		\OC_Hook::clear('OC_Filesystem', 'post_update');
		\OC_Hook::clear('OC_Filesystem', 'post_write');
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('resolvePathTestProvider')]
	public function testResolvePath($expected, $pathToTest): void {
		$storage1 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');

		$view = new View('');

		$result = $view->resolvePath($pathToTest);
		$this->assertEquals($expected, $result[1]);

		$exists = $view->file_exists($pathToTest);
		$this->assertTrue($exists);

		$exists = $view->file_exists($result[1]);
		$this->assertTrue($exists);
	}

	public static function resolvePathTestProvider(): array {
		return [
			['foo.txt', 'foo.txt'],
			['foo.txt', '/foo.txt'],
			['folder', 'folder'],
			['folder', '/folder'],
			['folder', 'folder/'],
			['folder', '/folder/'],
			['folder/bar.txt', 'folder/bar.txt'],
			['folder/bar.txt', '/folder/bar.txt'],
			['', ''],
			['', '/'],
		];
	}

	public function testUTF8Names(): void {
		$names = ['虚', '和知しゃ和で', 'regular ascii', 'sɨˈrɪlɪk', 'ѨѬ', 'أنا أحب القراءة كثيرا'];

		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');

		$rootView = new View('');
		foreach ($names as $name) {
			$rootView->file_put_contents('/' . $name, 'dummy content');
		}

		$list = $rootView->getDirectoryContent('/');

		$this->assertCount(count($names), $list);
		foreach ($list as $item) {
			$this->assertContains($item['name'], $names);
		}

		$cache = $storage->getCache();
		$scanner = $storage->getScanner();
		$scanner->scan('');

		$list = $cache->getFolderContents('');

		$this->assertCount(count($names), $list);
		foreach ($list as $item) {
			$this->assertContains($item['name'], $names);
		}
	}

	public function xtestLongPath() {
		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');

		$rootView = new View('');

		$longPath = '';
		$ds = DIRECTORY_SEPARATOR;
		/*
		 * 4096 is the maximum path length in file_cache.path in *nix
		 * 1024 is the max path length in mac
		 */
		$folderName = 'abcdefghijklmnopqrstuvwxyz012345678901234567890123456789';
		$tmpdirLength = strlen(Server::get(ITempManager::class)->getTemporaryFolder());
		if (\OC_Util::runningOnMac()) {
			$depth = ((1024 - $tmpdirLength) / 57);
		} else {
			$depth = ((4000 - $tmpdirLength) / 57);
		}
		foreach (range(0, $depth - 1) as $i) {
			$longPath .= $ds . $folderName;
			$result = $rootView->mkdir($longPath);
			$this->assertTrue($result, "mkdir failed on $i - path length: " . strlen($longPath));

			$result = $rootView->file_put_contents($longPath . "{$ds}test.txt", 'lorem');
			$this->assertEquals(5, $result, "file_put_contents failed on $i");

			$this->assertTrue($rootView->file_exists($longPath));
			$this->assertTrue($rootView->file_exists($longPath . "{$ds}test.txt"));
		}

		$cache = $storage->getCache();
		$scanner = $storage->getScanner();
		$scanner->scan('');

		$longPath = $folderName;
		foreach (range(0, $depth - 1) as $i) {
			$cachedFolder = $cache->get($longPath);
			$this->assertTrue(is_array($cachedFolder), "No cache entry for folder at $i");
			$this->assertEquals($folderName, $cachedFolder['name'], "Wrong cache entry for folder at $i");

			$cachedFile = $cache->get($longPath . '/test.txt');
			$this->assertTrue(is_array($cachedFile), "No cache entry for file at $i");
			$this->assertEquals('test.txt', $cachedFile['name'], "Wrong cache entry for file at $i");

			$longPath .= $ds . $folderName;
		}
	}

	public function testTouchNotSupported(): void {
		$storage = new TemporaryNoTouch([]);
		$scanner = $storage->getScanner();
		Filesystem::mount($storage, [], '/test/');
		$past = time() - 100;
		$storage->file_put_contents('test', 'foobar');
		$scanner->scan('');
		$view = new View('');
		$info = $view->getFileInfo('/test/test');

		$view->touch('/test/test', $past);
		$scanner->scanFile('test', Scanner::REUSE_ETAG);

		$info2 = $view->getFileInfo('/test/test');
		$this->assertSame($info['etag'], $info2['etag']);
	}

	public function testWatcherEtagCrossStorage(): void {
		$storage1 = new Temporary([]);
		$storage2 = new Temporary([]);
		$scanner1 = $storage1->getScanner();
		$scanner2 = $storage2->getScanner();
		$storage1->mkdir('sub');
		Filesystem::mount($storage1, [], '/test/');
		Filesystem::mount($storage2, [], '/test/sub/storage');

		$past = time() - 100;
		$storage2->file_put_contents('test.txt', 'foobar');
		$scanner1->scan('');
		$scanner2->scan('');
		$view = new View('');

		$storage2->getWatcher('')->setPolicy(Watcher::CHECK_ALWAYS);

		$oldFileInfo = $view->getFileInfo('/test/sub/storage/test.txt');
		$oldFolderInfo = $view->getFileInfo('/test');

		$storage2->getCache()->update($oldFileInfo->getId(), [
			'storage_mtime' => $past,
		]);

		$oldEtag = $oldFolderInfo->getEtag();

		$view->getFileInfo('/test/sub/storage/test.txt');
		$newFolderInfo = $view->getFileInfo('/test');

		$this->assertNotEquals($newFolderInfo->getEtag(), $oldEtag);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('absolutePathProvider')]
	public function testGetAbsolutePath($expectedPath, $relativePath): void {
		$view = new View('/files');
		$this->assertEquals($expectedPath, $view->getAbsolutePath($relativePath));
	}

	public function testPartFileInfo(): void {
		$storage = new Temporary([]);
		$scanner = $storage->getScanner();
		Filesystem::mount($storage, [], '/test/');
		$sizeWritten = $storage->file_put_contents('test.part', 'foobar');
		$scanner->scan('');
		$view = new View('/test');
		$info = $view->getFileInfo('test.part');

		$this->assertInstanceOf('\OCP\Files\FileInfo', $info);
		$this->assertNull($info->getId());
		$this->assertEquals(6, $sizeWritten);
		$this->assertEquals(6, $info->getSize());
		$this->assertEquals('foobar', $view->file_get_contents('test.part'));
	}

	public static function absolutePathProvider(): array {
		return [
			['/files/', ''],
			['/files/0', '0'],
			['/files/false', 'false'],
			['/files/true', 'true'],
			['/files/', '/'],
			['/files/test', 'test'],
			['/files/test', '/test'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('chrootRelativePathProvider')]
	public function testChrootGetRelativePath($root, $absolutePath, $expectedPath): void {
		$view = new View('/files');
		$view->chroot($root);
		$this->assertEquals($expectedPath, $view->getRelativePath($absolutePath));
	}

	public static function chrootRelativePathProvider(): array {
		return self::relativePathProvider('/');
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('initRelativePathProvider')]
	public function testInitGetRelativePath($root, $absolutePath, $expectedPath): void {
		$view = new View($root);
		$this->assertEquals($expectedPath, $view->getRelativePath($absolutePath));
	}

	public static function initRelativePathProvider(): array {
		return self::relativePathProvider(null);
	}

	public static function relativePathProvider($missingRootExpectedPath): array {
		return [
			// No root - returns the path
			['', '/files', '/files'],
			['', '/files/', '/files/'],

			// Root equals path - /
			['/files/', '/files/', '/'],
			['/files/', '/files', '/'],
			['/files', '/files/', '/'],
			['/files', '/files', '/'],

			// False negatives: chroot fixes those by adding the leading slash.
			// But setting them up with this root (instead of chroot($root))
			// will fail them, although they should be the same.
			// TODO init should be fixed, so it also adds the leading slash
			['files/', '/files/', $missingRootExpectedPath],
			['files', '/files/', $missingRootExpectedPath],
			['files/', '/files', $missingRootExpectedPath],
			['files', '/files', $missingRootExpectedPath],

			// False negatives: Paths provided to the method should have a leading slash
			// TODO input should be checked to have a leading slash
			['/files/', 'files/', null],
			['/files', 'files/', null],
			['/files/', 'files', null],
			['/files', 'files', null],

			// with trailing slashes
			['/files/', '/files/0', '0'],
			['/files/', '/files/false', 'false'],
			['/files/', '/files/true', 'true'],
			['/files/', '/files/test', 'test'],
			['/files/', '/files/test/foo', 'test/foo'],

			// without trailing slashes
			// TODO false expectation: Should match "with trailing slashes"
			['/files', '/files/0', '/0'],
			['/files', '/files/false', '/false'],
			['/files', '/files/true', '/true'],
			['/files', '/files/test', '/test'],
			['/files', '/files/test/foo', '/test/foo'],

			// leading slashes
			['/files/', '/files_trashbin/', null],
			['/files', '/files_trashbin/', null],
			['/files/', '/files_trashbin', null],
			['/files', '/files_trashbin', null],

			// no leading slashes
			['files/', 'files_trashbin/', null],
			['files', 'files_trashbin/', null],
			['files/', 'files_trashbin', null],
			['files', 'files_trashbin', null],

			// mixed leading slashes
			['files/', '/files_trashbin/', null],
			['/files/', 'files_trashbin/', null],
			['files', '/files_trashbin/', null],
			['/files', 'files_trashbin/', null],
			['files/', '/files_trashbin', null],
			['/files/', 'files_trashbin', null],
			['files', '/files_trashbin', null],
			['/files', 'files_trashbin', null],

			['files', 'files_trashbin/test', null],
			['/files', '/files_trashbin/test', null],
			['/files', 'files_trashbin/test', null],
		];
	}

	public function testFileView(): void {
		$storage = new Temporary([]);
		$scanner = $storage->getScanner();
		$storage->file_put_contents('foo.txt', 'bar');
		Filesystem::mount($storage, [], '/test/');
		$scanner->scan('');
		$view = new View('/test/foo.txt');

		$this->assertEquals('bar', $view->file_get_contents(''));
		$fh = tmpfile();
		fwrite($fh, 'foo');
		rewind($fh);
		$view->file_put_contents('', $fh);
		$this->assertEquals('foo', $view->file_get_contents(''));
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('tooLongPathDataProvider')]
	public function testTooLongPath($operation, $param0 = null): void {
		$this->expectException(InvalidPathException::class);


		$longPath = '';
		// 4000 is the maximum path length in file_cache.path
		$folderName = 'abcdefghijklmnopqrstuvwxyz012345678901234567890123456789';
		$depth = (4000 / 57);
		foreach (range(0, $depth + 1) as $i) {
			$longPath .= '/' . $folderName;
		}

		$storage = new Temporary([]);
		$this->tempStorage = $storage; // for later hard cleanup
		Filesystem::mount($storage, [], '/');

		$rootView = new View('');

		if ($param0 === '@0') {
			$param0 = $longPath;
		}

		if ($operation === 'hash') {
			$param0 = $longPath;
			$longPath = 'md5';
		}

		call_user_func([$rootView, $operation], $longPath, $param0);
	}

	public static function tooLongPathDataProvider(): array {
		return [
			['getAbsolutePath'],
			['getRelativePath'],
			['getMountPoint'],
			['resolvePath'],
			['getLocalFile'],
			['mkdir'],
			['rmdir'],
			['opendir'],
			['is_dir'],
			['is_file'],
			['stat'],
			['filetype'],
			['filesize'],
			['readfile'],
			['isCreatable'],
			['isReadable'],
			['isUpdatable'],
			['isDeletable'],
			['isSharable'],
			['file_exists'],
			['filemtime'],
			['touch'],
			['file_get_contents'],
			['unlink'],
			['deleteAll'],
			['toTmpFile'],
			['getMimeType'],
			['free_space'],
			['getFileInfo'],
			['getDirectoryContent'],
			['getOwner'],
			['getETag'],
			['file_put_contents', 'ipsum'],
			['rename', '@0'],
			['copy', '@0'],
			['fopen', 'r'],
			['fromTmpFile', '@0'],
			['hash'],
			['hasUpdated', 0],
			['putFileInfo', []],
		];
	}

	public function testRenameCrossStoragePreserveMtime(): void {
		$storage1 = new Temporary([]);
		$storage2 = new Temporary([]);
		$storage1->mkdir('sub');
		$storage1->mkdir('foo');
		$storage1->file_put_contents('foo.txt', 'asd');
		$storage1->file_put_contents('foo/bar.txt', 'asd');
		Filesystem::mount($storage1, [], '/test/');
		Filesystem::mount($storage2, [], '/test/sub/storage');

		$view = new View('');
		$time = time() - 200;
		$view->touch('/test/foo.txt', $time);
		$view->touch('/test/foo', $time);
		$view->touch('/test/foo/bar.txt', $time);

		$view->rename('/test/foo.txt', '/test/sub/storage/foo.txt');

		$this->assertEquals($time, $view->filemtime('/test/sub/storage/foo.txt'));

		$view->rename('/test/foo', '/test/sub/storage/foo');

		$this->assertEquals($time, $view->filemtime('/test/sub/storage/foo/bar.txt'));
	}

	public function testRenameFailDeleteTargetKeepSource(): void {
		$this->doTestCopyRenameFail('rename');
	}

	public function testCopyFailDeleteTargetKeepSource(): void {
		$this->doTestCopyRenameFail('copy');
	}

	private function doTestCopyRenameFail($operation) {
		$storage1 = new Temporary([]);
		/** @var \PHPUnit\Framework\MockObject\MockObject|Temporary $storage2 */
		$storage2 = $this->getMockBuilder(TemporaryNoCross::class)
			->setConstructorArgs([[]])
			->onlyMethods(['fopen', 'writeStream'])
			->getMock();

		$storage2->method('writeStream')
			->willThrowException(new GenericFileException('Failed to copy stream'));

		$storage1->mkdir('sub');
		$storage1->file_put_contents('foo.txt', '0123456789ABCDEFGH');
		$storage1->mkdir('dirtomove');
		$storage1->file_put_contents('dirtomove/indir1.txt', '0123456'); // fits
		$storage1->file_put_contents('dirtomove/indir2.txt', '0123456789ABCDEFGH'); // doesn't fit
		$storage2->file_put_contents('existing.txt', '0123');
		$storage1->getScanner()->scan('');
		$storage2->getScanner()->scan('');
		Filesystem::mount($storage1, [], '/test/');
		Filesystem::mount($storage2, [], '/test/sub/storage');

		// move file
		$view = new View('');
		$this->assertTrue($storage1->file_exists('foo.txt'));
		$this->assertFalse($storage2->file_exists('foo.txt'));
		$this->assertFalse($view->$operation('/test/foo.txt', '/test/sub/storage/foo.txt'));
		$this->assertFalse($storage2->file_exists('foo.txt'));
		$this->assertFalse($storage2->getCache()->get('foo.txt'));
		$this->assertTrue($storage1->file_exists('foo.txt'));

		// if target exists, it will be deleted too
		$this->assertFalse($view->$operation('/test/foo.txt', '/test/sub/storage/existing.txt'));
		$this->assertFalse($storage2->file_exists('existing.txt'));
		$this->assertFalse($storage2->getCache()->get('existing.txt'));
		$this->assertTrue($storage1->file_exists('foo.txt'));

		// move folder
		$this->assertFalse($view->$operation('/test/dirtomove/', '/test/sub/storage/dirtomove/'));
		// since the move failed, the full source tree is kept
		$this->assertTrue($storage1->file_exists('dirtomove/indir1.txt'));
		$this->assertTrue($storage1->file_exists('dirtomove/indir2.txt'));
		// second file not moved/copied
		$this->assertFalse($storage2->file_exists('dirtomove/indir2.txt'));
		$this->assertFalse($storage2->getCache()->get('dirtomove/indir2.txt'));
	}

	public function testDeleteFailKeepCache(): void {
		/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject $storage */
		$storage = $this->getMockBuilder(Temporary::class)
			->setConstructorArgs([[]])
			->onlyMethods(['unlink'])
			->getMock();
		$storage->expects($this->once())
			->method('unlink')
			->willReturn(false);
		$scanner = $storage->getScanner();
		$cache = $storage->getCache();
		$storage->file_put_contents('foo.txt', 'asd');
		$scanner->scan('');
		Filesystem::mount($storage, [], '/test/');

		$view = new View('/test');

		$this->assertFalse($view->unlink('foo.txt'));
		$this->assertTrue($cache->inCache('foo.txt'));
	}

	public static function directoryTraversalProvider(): array {
		return [
			['../test/'],
			['..\\test\\my/../folder'],
			['/test/my/../foo\\'],
		];
	}

	/**
	 * @param string $root
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('directoryTraversalProvider')]
	public function testConstructDirectoryTraversalException($root): void {
		$this->expectException(\Exception::class);

		new View($root);
	}

	public function testRenameOverWrite(): void {
		$storage = new Temporary([]);
		$scanner = $storage->getScanner();
		$storage->mkdir('sub');
		$storage->mkdir('foo');
		$storage->file_put_contents('foo.txt', 'asd');
		$storage->file_put_contents('foo/bar.txt', 'asd');
		$scanner->scan('');
		Filesystem::mount($storage, [], '/test/');
		$view = new View('');
		$this->assertTrue($view->rename('/test/foo.txt', '/test/foo/bar.txt'));
	}

	public function testSetMountOptionsInStorage(): void {
		$mount = new MountPoint(Temporary::class, '/asd/', [[]], Filesystem::getLoader(), ['foo' => 'bar']);
		Filesystem::getMountManager()->addMount($mount);
		/** @var Common $storage */
		$storage = $mount->getStorage();
		$this->assertEquals($storage->getMountOption('foo'), 'bar');
	}

	public function testSetMountOptionsWatcherPolicy(): void {
		$mount = new MountPoint(Temporary::class, '/asd/', [[]], Filesystem::getLoader(), ['filesystem_check_changes' => Watcher::CHECK_NEVER]);
		Filesystem::getMountManager()->addMount($mount);
		/** @var Common $storage */
		$storage = $mount->getStorage();
		$watcher = $storage->getWatcher();
		$this->assertEquals(Watcher::CHECK_NEVER, $watcher->getPolicy());
	}

	public function testGetAbsolutePathOnNull(): void {
		$view = new View();
		$this->assertNull($view->getAbsolutePath(null));
	}

	public function testGetRelativePathOnNull(): void {
		$view = new View();
		$this->assertNull($view->getRelativePath(null));
	}


	public function testNullAsRoot(): void {
		$this->expectException(\TypeError::class);

		new View(null);
	}

	/**
	 * e.g. reading from a folder that's being renamed
	 *
	 *
	 *
	 * @param string $rootPath
	 * @param string $pathPrefix
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataLockPaths')]
	public function testReadFromWriteLockedPath($rootPath, $pathPrefix): void {
		$this->expectException(LockedException::class);

		$rootPath = str_replace('{folder}', 'files', $rootPath);
		$pathPrefix = str_replace('{folder}', 'files', $pathPrefix);

		$view = new View($rootPath);
		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');
		$this->assertTrue($view->lockFile($pathPrefix . '/foo/bar', ILockingProvider::LOCK_EXCLUSIVE));
		$view->lockFile($pathPrefix . '/foo/bar/asd', ILockingProvider::LOCK_SHARED);
	}

	/**
	 * Reading from a files_encryption folder that's being renamed
	 *
	 *
	 * @param string $rootPath
	 * @param string $pathPrefix
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataLockPaths')]
	public function testReadFromWriteUnlockablePath($rootPath, $pathPrefix): void {
		$rootPath = str_replace('{folder}', 'files_encryption', $rootPath);
		$pathPrefix = str_replace('{folder}', 'files_encryption', $pathPrefix);

		$view = new View($rootPath);
		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');
		$this->assertFalse($view->lockFile($pathPrefix . '/foo/bar', ILockingProvider::LOCK_EXCLUSIVE));
		$this->assertFalse($view->lockFile($pathPrefix . '/foo/bar/asd', ILockingProvider::LOCK_SHARED));
	}

	/**
	 * e.g. writing a file that's being downloaded
	 *
	 *
	 *
	 * @param string $rootPath
	 * @param string $pathPrefix
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataLockPaths')]
	public function testWriteToReadLockedFile($rootPath, $pathPrefix): void {
		$this->expectException(LockedException::class);

		$rootPath = str_replace('{folder}', 'files', $rootPath);
		$pathPrefix = str_replace('{folder}', 'files', $pathPrefix);

		$view = new View($rootPath);
		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');
		$this->assertTrue($view->lockFile($pathPrefix . '/foo/bar', ILockingProvider::LOCK_SHARED));
		$view->lockFile($pathPrefix . '/foo/bar', ILockingProvider::LOCK_EXCLUSIVE);
	}

	/**
	 * Writing a file that's being downloaded
	 *
	 *
	 * @param string $rootPath
	 * @param string $pathPrefix
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataLockPaths')]
	public function testWriteToReadUnlockableFile($rootPath, $pathPrefix): void {
		$rootPath = str_replace('{folder}', 'files_encryption', $rootPath);
		$pathPrefix = str_replace('{folder}', 'files_encryption', $pathPrefix);

		$view = new View($rootPath);
		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');
		$this->assertFalse($view->lockFile($pathPrefix . '/foo/bar', ILockingProvider::LOCK_SHARED));
		$this->assertFalse($view->lockFile($pathPrefix . '/foo/bar', ILockingProvider::LOCK_EXCLUSIVE));
	}

	/**
	 * Test that locks are on mount point paths instead of mount root
	 */
	public function testLockLocalMountPointPathInsteadOfStorageRoot(): void {
		$lockingProvider = Server::get(ILockingProvider::class);
		$view = new View('/testuser/files/');
		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');
		$mountedStorage = new Temporary([]);
		Filesystem::mount($mountedStorage, [], '/testuser/files/mountpoint');

		$this->assertTrue(
			$view->lockFile('/mountpoint', ILockingProvider::LOCK_EXCLUSIVE, true),
			'Can lock mount point'
		);

		// no exception here because storage root was not locked
		$mountedStorage->acquireLock('', ILockingProvider::LOCK_EXCLUSIVE, $lockingProvider);

		$thrown = false;
		try {
			$storage->acquireLock('/testuser/files/mountpoint', ILockingProvider::LOCK_EXCLUSIVE, $lockingProvider);
		} catch (LockedException $e) {
			$thrown = true;
		}
		$this->assertTrue($thrown, 'Mount point path was locked on root storage');

		$lockingProvider->releaseAll();
	}

	/**
	 * Test that locks are on mount point paths and also mount root when requested
	 */
	public function testLockStorageRootButNotLocalMountPoint(): void {
		$lockingProvider = Server::get(ILockingProvider::class);
		$view = new View('/testuser/files/');
		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');
		$mountedStorage = new Temporary([]);
		Filesystem::mount($mountedStorage, [], '/testuser/files/mountpoint');

		$this->assertTrue(
			$view->lockFile('/mountpoint', ILockingProvider::LOCK_EXCLUSIVE, false),
			'Can lock mount point'
		);

		$thrown = false;
		try {
			$mountedStorage->acquireLock('', ILockingProvider::LOCK_EXCLUSIVE, $lockingProvider);
		} catch (LockedException $e) {
			$thrown = true;
		}
		$this->assertTrue($thrown, 'Mount point storage root was locked on original storage');

		// local mount point was not locked
		$storage->acquireLock('/testuser/files/mountpoint', ILockingProvider::LOCK_EXCLUSIVE, $lockingProvider);

		$lockingProvider->releaseAll();
	}

	/**
	 * Test that locks are on mount point paths and also mount root when requested
	 */
	public function testLockMountPointPathFailReleasesBoth(): void {
		$lockingProvider = Server::get(ILockingProvider::class);
		$view = new View('/testuser/files/');
		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');
		$mountedStorage = new Temporary([]);
		Filesystem::mount($mountedStorage, [], '/testuser/files/mountpoint.txt');

		// this would happen if someone is writing on the mount point
		$mountedStorage->acquireLock('', ILockingProvider::LOCK_EXCLUSIVE, $lockingProvider);

		$thrown = false;
		try {
			// this actually acquires two locks, one on the mount point and one on the storage root,
			// but the one on the storage root will fail
			$view->lockFile('/mountpoint.txt', ILockingProvider::LOCK_SHARED);
		} catch (LockedException $e) {
			$thrown = true;
		}
		$this->assertTrue($thrown, 'Cannot acquire shared lock because storage root is already locked');

		// from here we expect that the lock on the local mount point was released properly
		// so acquiring an exclusive lock will succeed
		$storage->acquireLock('/testuser/files/mountpoint.txt', ILockingProvider::LOCK_EXCLUSIVE, $lockingProvider);

		$lockingProvider->releaseAll();
	}

	public static function dataLockPaths(): array {
		return [
			['/testuser/{folder}', ''],
			['/testuser', '/{folder}'],
			['', '/testuser/{folder}'],
		];
	}

	public static function pathRelativeToFilesProvider(): array {
		return [
			['admin/files', ''],
			['admin/files/x', 'x'],
			['/admin/files', ''],
			['/admin/files/sub', 'sub'],
			['/admin/files/sub/', 'sub'],
			['/admin/files/sub/sub2', 'sub/sub2'],
			['//admin//files/sub//sub2', 'sub/sub2'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('pathRelativeToFilesProvider')]
	public function testGetPathRelativeToFiles($path, $expectedPath): void {
		$view = new View();
		$this->assertEquals($expectedPath, $view->getPathRelativeToFiles($path));
	}

	public static function pathRelativeToFilesProviderExceptionCases(): array {
		return [
			[''],
			['x'],
			['files'],
			['/files'],
			['/admin/files_versions/abc'],
		];
	}

	/**
	 * @param string $path
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('pathRelativeToFilesProviderExceptionCases')]
	public function testGetPathRelativeToFilesWithInvalidArgument($path): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('$absolutePath must be relative to "files"');

		$view = new View();
		$view->getPathRelativeToFiles($path);
	}

	public function testChangeLock(): void {
		$view = new View('/testuser/files/');
		$storage = new Temporary([]);
		Filesystem::mount($storage, [], '/');

		$view->lockFile('/test/sub', ILockingProvider::LOCK_SHARED);
		$this->assertTrue($this->isFileLocked($view, '/test//sub', ILockingProvider::LOCK_SHARED));
		$this->assertFalse($this->isFileLocked($view, '/test//sub', ILockingProvider::LOCK_EXCLUSIVE));

		$view->changeLock('//test/sub', ILockingProvider::LOCK_EXCLUSIVE);
		$this->assertTrue($this->isFileLocked($view, '/test//sub', ILockingProvider::LOCK_EXCLUSIVE));

		$view->changeLock('test/sub', ILockingProvider::LOCK_SHARED);
		$this->assertTrue($this->isFileLocked($view, '/test//sub', ILockingProvider::LOCK_SHARED));

		$view->unlockFile('/test/sub/', ILockingProvider::LOCK_SHARED);

		$this->assertFalse($this->isFileLocked($view, '/test//sub', ILockingProvider::LOCK_SHARED));
		$this->assertFalse($this->isFileLocked($view, '/test//sub', ILockingProvider::LOCK_EXCLUSIVE));
	}

	public static function hookPathProvider(): array {
		return [
			['/foo/files', '/foo', true],
			['/foo/files/bar', '/foo', true],
			['/foo', '/foo', false],
			['/foo', '/files/foo', true],
			['/foo', 'filesfoo', false],
			['', '/foo/files', true],
			['', '/foo/files/bar.txt', true],
		];
	}

	/**
	 * @param $root
	 * @param $path
	 * @param $shouldEmit
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('hookPathProvider')]
	public function testHookPaths($root, $path, $shouldEmit): void {
		$filesystemReflection = new \ReflectionClass(Filesystem::class);
		$defaultRootValue = $filesystemReflection->getProperty('defaultInstance');
		$defaultRootValue->setAccessible(true);
		$oldRoot = $defaultRootValue->getValue();
		$defaultView = new View('/foo/files');
		$defaultRootValue->setValue(null, $defaultView);
		$view = new View($root);
		$result = self::invokePrivate($view, 'shouldEmitHooks', [$path]);
		$defaultRootValue->setValue(null, $oldRoot);
		$this->assertEquals($shouldEmit, $result);
	}

	/**
	 * Create test movable mount points
	 *
	 * @param array $mountPoints array of mount point locations
	 * @return array array of MountPoint objects
	 */
	private function createTestMovableMountPoints($mountPoints) {
		$mounts = [];
		foreach ($mountPoints as $mountPoint) {
			$storage = $this->getMockBuilder(Storage::class)
				->onlyMethods([])
				->getMock();
			$storage->method('getId')->willReturn('non-null-id');
			$storage->method('getStorageCache')->willReturnCallback(function () use ($storage) {
				return new \OC\Files\Cache\Storage($storage, true, Server::get(IDBConnection::class));
			});

			$mounts[] = $this->getMockBuilder(TestMoveableMountPoint::class)
				->onlyMethods(['moveMount'])
				->setConstructorArgs([$storage, $mountPoint])
				->getMock();
		}

		/** @var IMountProvider|\PHPUnit\Framework\MockObject\MockObject $mountProvider */
		$mountProvider = $this->createMock(IMountProvider::class);
		$mountProvider->expects($this->any())
			->method('getMountsForUser')
			->willReturn($mounts);

		$mountProviderCollection = Server::get(IMountProviderCollection::class);
		$mountProviderCollection->registerProvider($mountProvider);

		return $mounts;
	}

	/**
	 * Test mount point move
	 */
	public function testMountPointMove(): void {
		self::loginAsUser($this->user);

		[$mount1, $mount2] = $this->createTestMovableMountPoints([
			$this->user . '/files/mount1',
			$this->user . '/files/mount2',
		]);
		$mount1->expects($this->once())
			->method('moveMount')
			->willReturn(true);

		$mount2->expects($this->once())
			->method('moveMount')
			->willReturn(true);

		$view = new View('/' . $this->user . '/files/');
		$view->mkdir('sub');

		$this->assertTrue($view->rename('mount1', 'renamed_mount'), 'Can rename mount point');
		$this->assertTrue($view->rename('mount2', 'sub/moved_mount'), 'Can move a mount point into a subdirectory');
	}

	public function testMoveMountPointOverwrite(): void {
		self::loginAsUser($this->user);

		[$mount1, $mount2] = $this->createTestMovableMountPoints([
			$this->user . '/files/mount1',
			$this->user . '/files/mount2',
		]);

		$mount1->expects($this->never())
			->method('moveMount');

		$mount2->expects($this->never())
			->method('moveMount');

		$view = new View('/' . $this->user . '/files/');

		$this->expectException(ForbiddenException::class);
		$view->rename('mount1', 'mount2');
	}

	public function testMoveMountPointIntoMount(): void {
		self::loginAsUser($this->user);

		[$mount1, $mount2] = $this->createTestMovableMountPoints([
			$this->user . '/files/mount1',
			$this->user . '/files/mount2',
		]);

		$mount1->expects($this->never())
			->method('moveMount');

		$mount2->expects($this->never())
			->method('moveMount');

		$view = new View('/' . $this->user . '/files/');

		$this->expectException(ForbiddenException::class);
		$view->rename('mount1', 'mount2/sub');
	}

	/**
	 * Test that moving a mount point into a shared folder is forbidden
	 */
	public function testMoveMountPointIntoSharedFolder(): void {
		self::loginAsUser($this->user);

		[$mount1, $mount2] = $this->createTestMovableMountPoints([
			$this->user . '/files/mount1',
			$this->user . '/files/mount2',
		]);

		$mount1->expects($this->never())
			->method('moveMount');

		$mount2->expects($this->once())
			->method('moveMount')
			->willReturn(true);

		$view = new View('/' . $this->user . '/files/');
		$view->mkdir('shareddir');
		$view->mkdir('shareddir/sub');
		$view->mkdir('shareddir/sub2');
		// Create a similar named but non-shared folder
		$view->mkdir('shareddir notshared');

		$fileId = $view->getFileInfo('shareddir')->getId();
		$userObject = Server::get(IUserManager::class)->createUser('test2', 'IHateNonMockableStaticClasses');

		$userFolder = \OC::$server->getUserFolder($this->user);
		$shareDir = $userFolder->get('shareddir');
		$shareManager = Server::get(IShareManager::class);
		$share = $shareManager->newShare();
		$share->setSharedWith('test2')
			->setSharedBy($this->user)
			->setShareType(IShare::TYPE_USER)
			->setPermissions(Constants::PERMISSION_READ)
			->setNode($shareDir);
		$shareManager->createShare($share);

		try {
			$view->rename('mount1', 'shareddir');
			$this->fail('Cannot overwrite shared folder');
		} catch (ForbiddenException $e) {

		}
		try {
			$view->rename('mount1', 'shareddir/sub');
			$this->fail('Cannot move mount point into shared folder');
		} catch (ForbiddenException $e) {

		}
		try {
			$view->rename('mount1', 'shareddir/sub/sub2');
			$this->fail('Cannot move mount point into shared subfolder');
		} catch (ForbiddenException $e) {

		}
		$this->assertTrue($view->rename('mount2', 'shareddir notshared/sub'), 'Can move mount point into a similarly named but non-shared folder');

		$shareManager->deleteShare($share);
		$userObject->delete();
	}

	public static function basicOperationProviderForLocks(): array {
		return [
			// --- write hook ----
			[
				'touch',
				['touch-create.txt'],
				'touch-create.txt',
				'create',
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_EXCLUSIVE,
				ILockingProvider::LOCK_SHARED,
			],
			[
				'fopen',
				['test-write.txt', 'w'],
				'test-write.txt',
				'write',
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_EXCLUSIVE,
				null,
				// exclusive lock stays until fclose
				ILockingProvider::LOCK_EXCLUSIVE,
			],
			[
				'mkdir',
				['newdir'],
				'newdir',
				'write',
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_EXCLUSIVE,
				ILockingProvider::LOCK_SHARED,
			],
			[
				'file_put_contents',
				['file_put_contents.txt', 'blah'],
				'file_put_contents.txt',
				'write',
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_EXCLUSIVE,
				ILockingProvider::LOCK_SHARED,
				null,
				0,
			],

			// ---- delete hook ----
			[
				'rmdir',
				['dir'],
				'dir',
				'delete',
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_EXCLUSIVE,
				ILockingProvider::LOCK_SHARED,
			],
			[
				'unlink',
				['test.txt'],
				'test.txt',
				'delete',
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_EXCLUSIVE,
				ILockingProvider::LOCK_SHARED,
			],

			// ---- read hook (no post hooks) ----
			[
				'file_get_contents',
				['test.txt'],
				'test.txt',
				'read',
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				null,
				null,
				false,
			],
			[
				'fopen',
				['test.txt', 'r'],
				'test.txt',
				'read',
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				null,
			],
			[
				'opendir',
				['dir'],
				'dir',
				'read',
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				null,
			],

			// ---- no lock, touch hook ---
			['touch', ['test.txt'], 'test.txt', 'touch', null, null, null],

			// ---- no hooks, no locks ---
			['is_dir', ['dir'], 'dir', null],
			['is_file', ['dir'], 'dir', null],
			[
				'stat',
				['dir'],
				'dir',
				null,
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				null,
				false,
			],
			[
				'filetype',
				['dir'],
				'dir',
				null,
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				null,
				false,
			],
			[
				'filesize',
				['dir'],
				'dir',
				null,
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				null,
				/* Return an int */
				100
			],
			['isCreatable', ['dir'], 'dir', null],
			['isReadable', ['dir'], 'dir', null],
			['isUpdatable', ['dir'], 'dir', null],
			['isDeletable', ['dir'], 'dir', null],
			['isSharable', ['dir'], 'dir', null],
			['file_exists', ['dir'], 'dir', null],
			[
				'filemtime',
				['dir'],
				'dir',
				null,
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				ILockingProvider::LOCK_SHARED,
				null,
				false,
			],
		];
	}

	/**
	 * Test whether locks are set before and after the operation
	 *
	 *
	 * @param string $operation operation name on the view
	 * @param array $operationArgs arguments for the operation
	 * @param string $lockedPath path of the locked item to check
	 * @param string $hookType hook type
	 * @param int $expectedLockBefore expected lock during pre hooks
	 * @param int $expectedLockDuring expected lock during operation
	 * @param int $expectedLockAfter expected lock during post hooks
	 * @param int $expectedStrayLock expected lock after returning, should
	 *                               be null (unlock) for most operations
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('basicOperationProviderForLocks')]
	public function testLockBasicOperation(
		$operation,
		$operationArgs,
		$lockedPath,
		$hookType,
		$expectedLockBefore = ILockingProvider::LOCK_SHARED,
		$expectedLockDuring = ILockingProvider::LOCK_SHARED,
		$expectedLockAfter = ILockingProvider::LOCK_SHARED,
		$expectedStrayLock = null,
		$returnValue = true,
	): void {
		$view = new View('/' . $this->user . '/files/');

		/** @var Temporary&MockObject $storage */
		$storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods([$operation])
			->getMock();

		/* Pause trash to avoid the trashbin intercepting rmdir and unlink calls */
		Server::get(ITrashManager::class)->pauseTrash();
		/* Same thing with encryption wrapper */
		Server::get(IStorageFactory::class)->removeStorageWrapper('oc_encryption');

		Filesystem::mount($storage, [], $this->user . '/');

		// work directly on disk because mkdir might be mocked
		$realPath = $storage->getSourcePath('');
		mkdir($realPath . '/files');
		mkdir($realPath . '/files/dir');
		file_put_contents($realPath . '/files/test.txt', 'blah');
		$storage->getScanner()->scan('files');

		$storage->expects($this->once())
			->method($operation)
			->willReturnCallback(
				function () use ($view, $lockedPath, &$lockTypeDuring, $returnValue) {
					$lockTypeDuring = $this->getFileLockType($view, $lockedPath);

					return $returnValue;
				}
			);

		$this->assertNull($this->getFileLockType($view, $lockedPath), 'File not locked before operation');

		$this->connectMockHooks($hookType, $view, $lockedPath, $lockTypePre, $lockTypePost);

		// do operation
		call_user_func_array([$view, $operation], $operationArgs);

		if ($hookType !== null) {
			$this->assertEquals($expectedLockBefore, $lockTypePre, 'File locked properly during pre-hook');
			$this->assertEquals($expectedLockAfter, $lockTypePost, 'File locked properly during post-hook');
			$this->assertEquals($expectedLockDuring, $lockTypeDuring, 'File locked properly during operation');
		} else {
			$this->assertNull($lockTypeDuring, 'File not locked during operation');
		}

		$this->assertEquals($expectedStrayLock, $this->getFileLockType($view, $lockedPath));

		/* Resume trash to avoid side effects */
		Server::get(ITrashManager::class)->resumeTrash();
	}

	/**
	 * Test locks for file_put_content with stream.
	 * This code path uses $storage->fopen instead
	 */
	public function testLockFilePutContentWithStream(): void {
		$view = new View('/' . $this->user . '/files/');

		$path = 'test_file_put_contents.txt';
		/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject $storage */
		$storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods(['fopen'])
			->getMock();

		Filesystem::mount($storage, [], $this->user . '/');
		$storage->mkdir('files');

		$storage->expects($this->once())
			->method('fopen')
			->willReturnCallback(
				function () use ($view, $path, &$lockTypeDuring) {
					$lockTypeDuring = $this->getFileLockType($view, $path);

					return fopen('php://temp', 'r+');
				}
			);

		$this->connectMockHooks('write', $view, $path, $lockTypePre, $lockTypePost);

		$this->assertNull($this->getFileLockType($view, $path), 'File not locked before operation');

		// do operation
		$view->file_put_contents($path, fopen('php://temp', 'r+'));

		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypePre, 'File locked properly during pre-hook');
		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypePost, 'File locked properly during post-hook');
		$this->assertEquals(ILockingProvider::LOCK_EXCLUSIVE, $lockTypeDuring, 'File locked properly during operation');

		$this->assertNull($this->getFileLockType($view, $path));
	}

	/**
	 * Test locks for fopen with fclose at the end
	 */
	public function testLockFopen(): void {
		$view = new View('/' . $this->user . '/files/');

		$path = 'test_file_put_contents.txt';
		/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject $storage */
		$storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods(['fopen'])
			->getMock();

		Filesystem::mount($storage, [], $this->user . '/');
		$storage->mkdir('files');

		$storage->expects($this->once())
			->method('fopen')
			->willReturnCallback(
				function () use ($view, $path, &$lockTypeDuring) {
					$lockTypeDuring = $this->getFileLockType($view, $path);

					return fopen('php://temp', 'r+');
				}
			);

		$this->connectMockHooks('write', $view, $path, $lockTypePre, $lockTypePost);

		$this->assertNull($this->getFileLockType($view, $path), 'File not locked before operation');

		// do operation
		$res = $view->fopen($path, 'w');

		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypePre, 'File locked properly during pre-hook');
		$this->assertEquals(ILockingProvider::LOCK_EXCLUSIVE, $lockTypeDuring, 'File locked properly during operation');
		$this->assertNull($lockTypePost, 'No post hook, no lock check possible');

		$this->assertEquals(ILockingProvider::LOCK_EXCLUSIVE, $lockTypeDuring, 'File still locked after fopen');

		fclose($res);

		$this->assertNull($this->getFileLockType($view, $path), 'File unlocked after fclose');
	}

	/**
	 * Test locks for fopen with fclose at the end
	 *
	 *
	 * @param string $operation operation name on the view
	 * @param array $operationArgs arguments for the operation
	 * @param string $path path of the locked item to check
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('basicOperationProviderForLocks')]
	public function testLockBasicOperationUnlocksAfterException(
		$operation,
		$operationArgs,
		$path,
	): void {
		if ($operation === 'touch') {
			$this->markTestSkipped('touch handles storage exceptions internally');
		}
		$view = new View('/' . $this->user . '/files/');

		/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject $storage */
		$storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods([$operation])
			->getMock();

		/* Pause trash to avoid the trashbin intercepting rmdir and unlink calls */
		Server::get(ITrashManager::class)->pauseTrash();
		/* Same thing with encryption wrapper */
		Server::get(IStorageFactory::class)->removeStorageWrapper('oc_encryption');

		Filesystem::mount($storage, [], $this->user . '/');

		// work directly on disk because mkdir might be mocked
		$realPath = $storage->getSourcePath('');
		mkdir($realPath . '/files');
		mkdir($realPath . '/files/dir');
		file_put_contents($realPath . '/files/test.txt', 'blah');
		$storage->getScanner()->scan('files');

		$storage->expects($this->once())
			->method($operation)
			->willReturnCallback(
				function (): void {
					throw new \Exception('Simulated exception');
				}
			);

		$thrown = false;
		try {
			call_user_func_array([$view, $operation], $operationArgs);
		} catch (\Exception $e) {
			$thrown = true;
			$this->assertEquals('Simulated exception', $e->getMessage());
		}
		$this->assertTrue($thrown, 'Exception was rethrown');
		$this->assertNull($this->getFileLockType($view, $path), 'File got unlocked after exception');

		/* Resume trash to avoid side effects */
		Server::get(ITrashManager::class)->resumeTrash();
	}

	public function testLockBasicOperationUnlocksAfterLockException(): void {
		$view = new View('/' . $this->user . '/files/');

		$storage = new Temporary([]);

		Filesystem::mount($storage, [], $this->user . '/');

		$storage->mkdir('files');
		$storage->mkdir('files/dir');
		$storage->file_put_contents('files/test.txt', 'blah');
		$storage->getScanner()->scan('files');

		// get a shared lock
		$handle = $view->fopen('test.txt', 'r');

		$thrown = false;
		try {
			// try (and fail) to get a write lock
			$view->unlink('test.txt');
		} catch (\Exception $e) {
			$thrown = true;
			$this->assertInstanceOf(LockedException::class, $e);
		}
		$this->assertTrue($thrown, 'Exception was rethrown');

		// clean shared lock
		fclose($handle);

		$this->assertNull($this->getFileLockType($view, 'test.txt'), 'File got unlocked');
	}

	/**
	 * Test locks for fopen with fclose at the end
	 *
	 *
	 * @param string $operation operation name on the view
	 * @param array $operationArgs arguments for the operation
	 * @param string $path path of the locked item to check
	 * @param string $hookType hook type
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('basicOperationProviderForLocks')]
	public function testLockBasicOperationUnlocksAfterCancelledHook(
		$operation,
		$operationArgs,
		$path,
		$hookType,
	): void {
		$view = new View('/' . $this->user . '/files/');

		/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject $storage */
		$storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods([$operation])
			->getMock();

		Filesystem::mount($storage, [], $this->user . '/');
		$storage->mkdir('files');

		Util::connectHook(
			Filesystem::CLASSNAME,
			$hookType,
			HookHelper::class,
			'cancellingCallback'
		);

		call_user_func_array([$view, $operation], $operationArgs);

		$this->assertNull($this->getFileLockType($view, $path), 'File got unlocked after exception');
	}

	public static function lockFileRenameOrCopyDataProvider(): array {
		return [
			['rename', ILockingProvider::LOCK_EXCLUSIVE],
			['copy', ILockingProvider::LOCK_SHARED],
		];
	}

	/**
	 * Test locks for rename or copy operation
	 *
	 *
	 * @param string $operation operation to be done on the view
	 * @param int $expectedLockTypeSourceDuring expected lock type on source file during
	 *                                          the operation
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('lockFileRenameOrCopyDataProvider')]
	public function testLockFileRename($operation, $expectedLockTypeSourceDuring): void {
		$view = new View('/' . $this->user . '/files/');

		/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject $storage */
		$storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods([$operation, 'getMetaData', 'filemtime'])
			->getMock();

		$storage->expects($this->any())
			->method('getMetaData')
			->willReturn([
				'mtime' => 1885434487,
				'etag' => '',
				'mimetype' => 'text/plain',
				'permissions' => Constants::PERMISSION_ALL,
				'size' => 3
			]);
		$storage->expects($this->any())
			->method('filemtime')
			->willReturn(123456789);

		$sourcePath = 'original.txt';
		$targetPath = 'target.txt';

		/* Disable encryption wrapper to avoid it intercepting mocked call */
		Server::get(IStorageFactory::class)->removeStorageWrapper('oc_encryption');

		Filesystem::mount($storage, [], $this->user . '/');
		$storage->mkdir('files');
		$view->file_put_contents($sourcePath, 'meh');

		$storage->expects($this->once())
			->method($operation)
			->willReturnCallback(
				function () use ($view, $sourcePath, $targetPath, &$lockTypeSourceDuring, &$lockTypeTargetDuring) {
					$lockTypeSourceDuring = $this->getFileLockType($view, $sourcePath);
					$lockTypeTargetDuring = $this->getFileLockType($view, $targetPath);

					return true;
				}
			);

		$this->connectMockHooks($operation, $view, $sourcePath, $lockTypeSourcePre, $lockTypeSourcePost);
		$this->connectMockHooks($operation, $view, $targetPath, $lockTypeTargetPre, $lockTypeTargetPost);

		$this->assertNull($this->getFileLockType($view, $sourcePath), 'Source file not locked before operation');
		$this->assertNull($this->getFileLockType($view, $targetPath), 'Target file not locked before operation');

		$view->$operation($sourcePath, $targetPath);

		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeSourcePre, 'Source file locked properly during pre-hook');
		$this->assertEquals($expectedLockTypeSourceDuring, $lockTypeSourceDuring, 'Source file locked properly during operation');
		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeSourcePost, 'Source file locked properly during post-hook');

		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeTargetPre, 'Target file locked properly during pre-hook');
		$this->assertEquals(ILockingProvider::LOCK_EXCLUSIVE, $lockTypeTargetDuring, 'Target file locked properly during operation');
		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeTargetPost, 'Target file locked properly during post-hook');

		$this->assertNull($this->getFileLockType($view, $sourcePath), 'Source file not locked after operation');
		$this->assertNull($this->getFileLockType($view, $targetPath), 'Target file not locked after operation');
	}

	/**
	 * simulate a failed copy operation.
	 * We expect that we catch the exception, free the lock and re-throw it.
	 *
	 */
	public function testLockFileCopyException(): void {
		$this->expectException(\Exception::class);

		$view = new View('/' . $this->user . '/files/');

		/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject $storage */
		$storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods(['copy'])
			->getMock();

		$sourcePath = 'original.txt';
		$targetPath = 'target.txt';

		/* Disable encryption wrapper to avoid it intercepting mocked call */
		Server::get(IStorageFactory::class)->removeStorageWrapper('oc_encryption');

		Filesystem::mount($storage, [], $this->user . '/');
		$storage->mkdir('files');
		$view->file_put_contents($sourcePath, 'meh');

		$storage->expects($this->once())
			->method('copy')
			->willReturnCallback(
				function (): void {
					throw new \Exception();
				}
			);

		$this->connectMockHooks('copy', $view, $sourcePath, $lockTypeSourcePre, $lockTypeSourcePost);
		$this->connectMockHooks('copy', $view, $targetPath, $lockTypeTargetPre, $lockTypeTargetPost);

		$this->assertNull($this->getFileLockType($view, $sourcePath), 'Source file not locked before operation');
		$this->assertNull($this->getFileLockType($view, $targetPath), 'Target file not locked before operation');

		try {
			$view->copy($sourcePath, $targetPath);
		} catch (\Exception $e) {
			$this->assertNull($this->getFileLockType($view, $sourcePath), 'Source file not locked after operation');
			$this->assertNull($this->getFileLockType($view, $targetPath), 'Target file not locked after operation');
			throw $e;
		}
	}

	/**
	 * Test rename operation: unlock first path when second path was locked
	 */
	public function testLockFileRenameUnlockOnException(): void {
		self::loginAsUser('test');

		$view = new View('/' . $this->user . '/files/');

		$sourcePath = 'original.txt';
		$targetPath = 'target.txt';
		$view->file_put_contents($sourcePath, 'meh');

		// simulate that the target path is already locked
		$view->lockFile($targetPath, ILockingProvider::LOCK_EXCLUSIVE);

		$this->assertNull($this->getFileLockType($view, $sourcePath), 'Source file not locked before operation');
		$this->assertEquals(ILockingProvider::LOCK_EXCLUSIVE, $this->getFileLockType($view, $targetPath), 'Target file is locked before operation');

		$thrown = false;
		try {
			$view->rename($sourcePath, $targetPath);
		} catch (LockedException $e) {
			$thrown = true;
		}

		$this->assertTrue($thrown, 'LockedException thrown');

		$this->assertNull($this->getFileLockType($view, $sourcePath), 'Source file not locked after operation');
		$this->assertEquals(ILockingProvider::LOCK_EXCLUSIVE, $this->getFileLockType($view, $targetPath), 'Target file still locked after operation');

		$view->unlockFile($targetPath, ILockingProvider::LOCK_EXCLUSIVE);
	}

	/**
	 * Test rename operation: unlock first path when second path was locked
	 */
	public function testGetOwner(): void {
		self::loginAsUser('test');

		$view = new View('/test/files/');

		$path = 'foo.txt';
		$view->file_put_contents($path, 'meh');

		$this->assertEquals('test', $view->getFileInfo($path)->getOwner()->getUID());

		$folderInfo = $view->getDirectoryContent('');
		$folderInfo = array_values(array_filter($folderInfo, function (FileInfo $info) {
			return $info->getName() === 'foo.txt';
		}));

		$this->assertEquals('test', $folderInfo[0]->getOwner()->getUID());

		$subStorage = new Temporary();
		Filesystem::mount($subStorage, [], '/test/files/asd');

		$folderInfo = $view->getDirectoryContent('');
		$folderInfo = array_values(array_filter($folderInfo, function (FileInfo $info) {
			return $info->getName() === 'asd';
		}));

		$this->assertEquals('test', $folderInfo[0]->getOwner()->getUID());
	}

	public static function lockFileRenameOrCopyCrossStorageDataProvider(): array {
		return [
			['rename', 'moveFromStorage', ILockingProvider::LOCK_EXCLUSIVE],
			['copy', 'copyFromStorage', ILockingProvider::LOCK_SHARED],
		];
	}

	/**
	 * Test locks for rename or copy operation cross-storage
	 *
	 *
	 * @param string $viewOperation operation to be done on the view
	 * @param string $storageOperation operation to be mocked on the storage
	 * @param int $expectedLockTypeSourceDuring expected lock type on source file during
	 *                                          the operation
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('lockFileRenameOrCopyCrossStorageDataProvider')]
	public function testLockFileRenameCrossStorage($viewOperation, $storageOperation, $expectedLockTypeSourceDuring): void {
		$view = new View('/' . $this->user . '/files/');

		/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject $storage */
		$storage = $this->getMockBuilder(Temporary::class)
			->onlyMethods([$storageOperation])
			->getMock();
		/** @var Temporary|\PHPUnit\Framework\MockObject\MockObject $storage2 */
		$storage2 = $this->getMockBuilder(Temporary::class)
			->onlyMethods([$storageOperation, 'getMetaData', 'filemtime'])
			->getMock();

		$storage2->expects($this->any())
			->method('getMetaData')
			->willReturn([
				'mtime' => 1885434487,
				'etag' => '',
				'mimetype' => 'text/plain',
				'permissions' => Constants::PERMISSION_ALL,
				'size' => 3
			]);
		$storage2->expects($this->any())
			->method('filemtime')
			->willReturn(123456789);

		$sourcePath = 'original.txt';
		$targetPath = 'substorage/target.txt';

		/* Disable encryption wrapper to avoid it intercepting mocked call */
		Server::get(IStorageFactory::class)->removeStorageWrapper('oc_encryption');

		Filesystem::mount($storage, [], $this->user . '/');
		Filesystem::mount($storage2, [], $this->user . '/files/substorage');
		$storage->mkdir('files');
		$view->file_put_contents($sourcePath, 'meh');
		$storage2->getUpdater()->update('');

		$storage->expects($this->never())
			->method($storageOperation);
		$storage2->expects($this->once())
			->method($storageOperation)
			->willReturnCallback(
				function () use ($view, $sourcePath, $targetPath, &$lockTypeSourceDuring, &$lockTypeTargetDuring) {
					$lockTypeSourceDuring = $this->getFileLockType($view, $sourcePath);
					$lockTypeTargetDuring = $this->getFileLockType($view, $targetPath);

					return true;
				}
			);

		$this->connectMockHooks($viewOperation, $view, $sourcePath, $lockTypeSourcePre, $lockTypeSourcePost);
		$this->connectMockHooks($viewOperation, $view, $targetPath, $lockTypeTargetPre, $lockTypeTargetPost);

		$this->assertNull($this->getFileLockType($view, $sourcePath), 'Source file not locked before operation');
		$this->assertNull($this->getFileLockType($view, $targetPath), 'Target file not locked before operation');

		$view->$viewOperation($sourcePath, $targetPath);

		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeSourcePre, 'Source file locked properly during pre-hook');
		$this->assertEquals($expectedLockTypeSourceDuring, $lockTypeSourceDuring, 'Source file locked properly during operation');
		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeSourcePost, 'Source file locked properly during post-hook');

		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeTargetPre, 'Target file locked properly during pre-hook');
		$this->assertEquals(ILockingProvider::LOCK_EXCLUSIVE, $lockTypeTargetDuring, 'Target file locked properly during operation');
		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeTargetPost, 'Target file locked properly during post-hook');

		$this->assertNull($this->getFileLockType($view, $sourcePath), 'Source file not locked after operation');
		$this->assertNull($this->getFileLockType($view, $targetPath), 'Target file not locked after operation');
	}

	/**
	 * Test locks when moving a mount point
	 */
	public function testLockMoveMountPoint(): void {
		self::loginAsUser('test');

		[$mount] = $this->createTestMovableMountPoints([
			$this->user . '/files/substorage',
		]);

		$view = new View('/' . $this->user . '/files/');
		$view->mkdir('subdir');

		$sourcePath = 'substorage';
		$targetPath = 'subdir/substorage_moved';

		$mount->expects($this->once())
			->method('moveMount')
			->willReturnCallback(
				function ($target) use ($mount, $view, $sourcePath, $targetPath, &$lockTypeSourceDuring, &$lockTypeTargetDuring, &$lockTypeSharedRootDuring) {
					$lockTypeSourceDuring = $this->getFileLockType($view, $sourcePath, true);
					$lockTypeTargetDuring = $this->getFileLockType($view, $targetPath, true);

					$lockTypeSharedRootDuring = $this->getFileLockType($view, $sourcePath, false);

					$mount->setMountPoint($target);

					return true;
				}
			);

		$this->connectMockHooks('rename', $view, $sourcePath, $lockTypeSourcePre, $lockTypeSourcePost, true);
		$this->connectMockHooks('rename', $view, $targetPath, $lockTypeTargetPre, $lockTypeTargetPost, true);
		// in pre-hook, mount point is still on $sourcePath
		$this->connectMockHooks('rename', $view, $sourcePath, $lockTypeSharedRootPre, $dummy, false);
		// in post-hook, mount point is now on $targetPath
		$this->connectMockHooks('rename', $view, $targetPath, $dummy, $lockTypeSharedRootPost, false);

		$this->assertNull($this->getFileLockType($view, $sourcePath, false), 'Shared storage root not locked before operation');
		$this->assertNull($this->getFileLockType($view, $sourcePath, true), 'Source path not locked before operation');
		$this->assertNull($this->getFileLockType($view, $targetPath, true), 'Target path not locked before operation');

		$view->rename($sourcePath, $targetPath);

		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeSourcePre, 'Source path locked properly during pre-hook');
		$this->assertEquals(ILockingProvider::LOCK_EXCLUSIVE, $lockTypeSourceDuring, 'Source path locked properly during operation');
		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeSourcePost, 'Source path locked properly during post-hook');

		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeTargetPre, 'Target path locked properly during pre-hook');
		$this->assertEquals(ILockingProvider::LOCK_EXCLUSIVE, $lockTypeTargetDuring, 'Target path locked properly during operation');
		$this->assertEquals(ILockingProvider::LOCK_SHARED, $lockTypeTargetPost, 'Target path locked properly during post-hook');

		$this->assertNull($lockTypeSharedRootPre, 'Shared storage root not locked during pre-hook');
		$this->assertNull($lockTypeSharedRootDuring, 'Shared storage root not locked during move');
		$this->assertNull($lockTypeSharedRootPost, 'Shared storage root not locked during post-hook');

		$this->assertNull($this->getFileLockType($view, $sourcePath, false), 'Shared storage root not locked after operation');
		$this->assertNull($this->getFileLockType($view, $sourcePath, true), 'Source path not locked after operation');
		$this->assertNull($this->getFileLockType($view, $targetPath, true), 'Target path not locked after operation');
	}

	/**
	 * Connect hook callbacks for hook type
	 *
	 * @param string $hookType hook type or null for none
	 * @param View $view view to check the lock on
	 * @param string $path path for which to check the lock
	 * @param int $lockTypePre variable to receive lock type that was active in the pre-hook
	 * @param int $lockTypePost variable to receive lock type that was active in the post-hook
	 * @param bool $onMountPoint true to check the mount point instead of the
	 *                           mounted storage
	 */
	private function connectMockHooks($hookType, $view, $path, &$lockTypePre, &$lockTypePost, $onMountPoint = false) {
		if ($hookType === null) {
			return;
		}

		$eventHandler = $this->getMockBuilder(TestEventHandler::class)
			->onlyMethods(['preCallback', 'postCallback'])
			->getMock();

		$eventHandler->expects($this->any())
			->method('preCallback')
			->willReturnCallback(
				function () use ($view, $path, $onMountPoint, &$lockTypePre): void {
					$lockTypePre = $this->getFileLockType($view, $path, $onMountPoint);
				}
			);
		$eventHandler->expects($this->any())
			->method('postCallback')
			->willReturnCallback(
				function () use ($view, $path, $onMountPoint, &$lockTypePost): void {
					$lockTypePost = $this->getFileLockType($view, $path, $onMountPoint);
				}
			);

		if ($hookType !== null) {
			Util::connectHook(
				Filesystem::CLASSNAME,
				$hookType,
				$eventHandler,
				'preCallback'
			);
			Util::connectHook(
				Filesystem::CLASSNAME,
				'post_' . $hookType,
				$eventHandler,
				'postCallback'
			);
		}
	}

	/**
	 * Returns the file lock type
	 *
	 * @param View $view view
	 * @param string $path path
	 * @param bool $onMountPoint true to check the mount point instead of the
	 *                           mounted storage
	 *
	 * @return int lock type or null if file was not locked
	 */
	private function getFileLockType(View $view, $path, $onMountPoint = false) {
		if ($this->isFileLocked($view, $path, ILockingProvider::LOCK_EXCLUSIVE, $onMountPoint)) {
			return ILockingProvider::LOCK_EXCLUSIVE;
		} elseif ($this->isFileLocked($view, $path, ILockingProvider::LOCK_SHARED, $onMountPoint)) {
			return ILockingProvider::LOCK_SHARED;
		}
		return null;
	}


	public function testRemoveMoveableMountPoint(): void {
		$mountPoint = '/' . $this->user . '/files/mount/';

		// Mock the mount point
		/** @var TestMoveableMountPoint|\PHPUnit\Framework\MockObject\MockObject $mount */
		$mount = $this->createMock(TestMoveableMountPoint::class);
		$mount->expects($this->once())
			->method('getMountPoint')
			->willReturn($mountPoint);
		$mount->expects($this->once())
			->method('removeMount')
			->willReturn('foo');
		$mount->expects($this->any())
			->method('getInternalPath')
			->willReturn('');

		// Register mount
		Filesystem::getMountManager()->addMount($mount);

		// Listen for events
		$eventHandler = $this->getMockBuilder(TestEventHandler::class)
			->onlyMethods(['umount', 'post_umount'])
			->getMock();
		$eventHandler->expects($this->once())
			->method('umount')
			->with([Filesystem::signal_param_path => '/mount']);
		$eventHandler->expects($this->once())
			->method('post_umount')
			->with([Filesystem::signal_param_path => '/mount']);
		Util::connectHook(
			Filesystem::CLASSNAME,
			'umount',
			$eventHandler,
			'umount'
		);
		Util::connectHook(
			Filesystem::CLASSNAME,
			'post_umount',
			$eventHandler,
			'post_umount'
		);

		//Delete the mountpoint
		$view = new View('/' . $this->user . '/files');
		$this->assertEquals('foo', $view->rmdir('mount'));
	}

	public static function mimeFilterProvider(): array {
		return [
			[null, ['test1.txt', 'test2.txt', 'test3.md', 'test4.png']],
			['text/plain', ['test1.txt', 'test2.txt']],
			['text/markdown', ['test3.md']],
			['text', ['test1.txt', 'test2.txt', 'test3.md']],
		];
	}

	/**
	 * @param string $filter
	 * @param string[] $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('mimeFilterProvider')]
	public function testGetDirectoryContentMimeFilter($filter, $expected): void {
		$storage1 = new Temporary();
		$root = self::getUniqueID('/');
		Filesystem::mount($storage1, [], $root . '/');
		$view = new View($root);

		$view->file_put_contents('test1.txt', 'asd');
		$view->file_put_contents('test2.txt', 'asd');
		$view->file_put_contents('test3.md', 'asd');
		$view->file_put_contents('test4.png', '');

		$content = $view->getDirectoryContent('', $filter);

		$files = array_map(function (FileInfo $info) {
			return $info->getName();
		}, $content);
		sort($files);

		$this->assertEquals($expected, $files);
	}

	public function testFilePutContentsClearsChecksum(): void {
		$storage = new Temporary([]);
		$scanner = $storage->getScanner();
		$storage->file_put_contents('foo.txt', 'bar');
		Filesystem::mount($storage, [], '/test/');
		$scanner->scan('');

		$view = new View('/test/foo.txt');
		$view->putFileInfo('.', ['checksum' => '42']);

		$this->assertEquals('bar', $view->file_get_contents(''));
		$fh = tmpfile();
		fwrite($fh, 'fooo');
		rewind($fh);
		clearstatcache();
		$view->file_put_contents('', $fh);
		$this->assertEquals('fooo', $view->file_get_contents(''));
		$data = $view->getFileInfo('.');
		$this->assertEquals('', $data->getChecksum());
	}

	public function testDeleteGhostFile(): void {
		$storage = new Temporary([]);
		$scanner = $storage->getScanner();
		$cache = $storage->getCache();
		$storage->file_put_contents('foo.txt', 'bar');
		Filesystem::mount($storage, [], '/test/');
		$scanner->scan('');

		$storage->unlink('foo.txt');

		$this->assertTrue($cache->inCache('foo.txt'));

		$view = new View('/test');
		$rootInfo = $view->getFileInfo('');
		$this->assertEquals(3, $rootInfo->getSize());
		$view->unlink('foo.txt');
		$newInfo = $view->getFileInfo('');

		$this->assertFalse($cache->inCache('foo.txt'));
		$this->assertNotEquals($rootInfo->getEtag(), $newInfo->getEtag());
		$this->assertEquals(0, $newInfo->getSize());
	}

	public function testDeleteGhostFolder(): void {
		$storage = new Temporary([]);
		$scanner = $storage->getScanner();
		$cache = $storage->getCache();
		$storage->mkdir('foo');
		$storage->file_put_contents('foo/foo.txt', 'bar');
		Filesystem::mount($storage, [], '/test/');
		$scanner->scan('');

		$storage->rmdir('foo');

		$this->assertTrue($cache->inCache('foo'));
		$this->assertTrue($cache->inCache('foo/foo.txt'));

		$view = new View('/test');
		$rootInfo = $view->getFileInfo('');
		$this->assertEquals(3, $rootInfo->getSize());
		$view->rmdir('foo');
		$newInfo = $view->getFileInfo('');

		$this->assertFalse($cache->inCache('foo'));
		$this->assertFalse($cache->inCache('foo/foo.txt'));
		$this->assertNotEquals($rootInfo->getEtag(), $newInfo->getEtag());
		$this->assertEquals(0, $newInfo->getSize());
	}

	public function testCreateParentDirectories(): void {
		$view = $this->getMockBuilder(View::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'is_file',
				'file_exists',
				'mkdir',
			])
			->getMock();

		$view->expects($this->exactly(3))
			->method('is_file')
			->willReturnMap([
				['/new', false],
				['/new/folder', false],
				['/new/folder/structure', false],
			]);
		$view->expects($this->exactly(3))
			->method('file_exists')
			->willReturnMap([
				['/new', true],
				['/new/folder', false],
				['/new/folder/structure', false],
			]);

		$calls = ['/new/folder', '/new/folder/structure'];
		$view->expects($this->exactly(2))
			->method('mkdir')
			->willReturnCallback(function ($dir) use (&$calls): void {
				$expected = array_shift($calls);
				$this->assertEquals($expected, $dir);
			});

		$this->assertTrue(self::invokePrivate($view, 'createParentDirectories', ['/new/folder/structure']));
	}

	public function testCreateParentDirectoriesWithExistingFile(): void {
		$view = $this->getMockBuilder(View::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'is_file',
				'file_exists',
				'mkdir',
			])
			->getMock();

		$view
			->expects($this->once())
			->method('is_file')
			->with('/file.txt')
			->willReturn(true);
		$this->assertFalse(self::invokePrivate($view, 'createParentDirectories', ['/file.txt/folder/structure']));
	}

	public function testCacheExtension(): void {
		$storage = new Temporary([]);
		$scanner = $storage->getScanner();
		$storage->file_put_contents('foo.txt', 'bar');
		$scanner->scan('');

		Filesystem::mount($storage, [], '/test/');
		$view = new View('/test');

		$info = $view->getFileInfo('/foo.txt');
		$this->assertEquals(0, $info->getUploadTime());
		$this->assertEquals(0, $info->getCreationTime());

		$view->putFileInfo('/foo.txt', ['upload_time' => 25]);

		$info = $view->getFileInfo('/foo.txt');
		$this->assertEquals(25, $info->getUploadTime());
		$this->assertEquals(0, $info->getCreationTime());
	}

	public function testFopenGone(): void {
		$storage = new Temporary([]);
		$scanner = $storage->getScanner();
		$storage->file_put_contents('foo.txt', 'bar');
		$scanner->scan('');
		$cache = $storage->getCache();

		Filesystem::mount($storage, [], '/test/');
		$view = new View('/test');

		$storage->unlink('foo.txt');

		$this->assertTrue($cache->inCache('foo.txt'));

		$this->assertFalse($view->fopen('foo.txt', 'r'));

		$this->assertFalse($cache->inCache('foo.txt'));
	}

	public function testMountpointParentsCreated(): void {
		$storage1 = $this->getTestStorage();
		Filesystem::mount($storage1, [], '/');

		$storage2 = $this->getTestStorage();
		Filesystem::mount($storage2, [], '/A/B/C');

		$rootView = new View('');

		$folderData = $rootView->getDirectoryContent('/');
		$this->assertCount(4, $folderData);
		$this->assertEquals('folder', $folderData[0]['name']);
		$this->assertEquals('foo.png', $folderData[1]['name']);
		$this->assertEquals('foo.txt', $folderData[2]['name']);
		$this->assertEquals('A', $folderData[3]['name']);

		$folderData = $rootView->getDirectoryContent('/A');
		$this->assertCount(1, $folderData);
		$this->assertEquals('B', $folderData[0]['name']);

		$folderData = $rootView->getDirectoryContent('/A/B');
		$this->assertCount(1, $folderData);
		$this->assertEquals('C', $folderData[0]['name']);

		$folderData = $rootView->getDirectoryContent('/A/B/C');
		$this->assertCount(3, $folderData);
		$this->assertEquals('folder', $folderData[0]['name']);
		$this->assertEquals('foo.png', $folderData[1]['name']);
		$this->assertEquals('foo.txt', $folderData[2]['name']);
	}

	public function testCopyPreservesContent() {
		$viewUser1 = new View('/' . 'userId' . '/files');
		$viewUser1->mkdir('');
		$viewUser1->file_put_contents('foo.txt', 'foo');
		$viewUser1->copy('foo.txt', 'bar.txt');
		$this->assertEquals('foo', $viewUser1->file_get_contents('bar.txt'));
	}
}
