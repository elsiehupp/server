<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OC\Remote;

use OC\Remote\Api\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\Remote\IInstance;

/**
 * Provides some basic info about a remote Nextcloud instance
 */
class Instance implements IInstance {
	/** @var string */
	private $url;

	/** @var ICache */
	private $cache;

	/** @var IClientService */
	private $clientService;

	/** @var array|null */
	private $status;

	/**
	 * @param string $url
	 * @param ICache $cache
	 * @param IClientService $clientService
	 */
	public function __construct($url, ICache $cache, IClientService $clientService) {
		$url = str_replace('https://', '', $url);
		$this->url = str_replace('http://', '', $url);
		$this->cache = $cache;
		$this->clientService = $clientService;
	}

	/**
	 * @return string The url of the remote server without protocol
	 */
	public function getUrl() {
		return $this->url;
	}

	/**
	 * @return string The of the remote server with protocol
	 */
	public function getFullUrl() {
		return $this->getProtocol() . '://' . $this->getUrl();
	}

	/**
	 * @return string The full version string in '13.1.2.3' format
	 */
	public function getVersion() {
		$status = $this->getStatus();
		return $status['version'];
	}

	/**
	 * @return string 'http' or 'https'
	 */
	public function getProtocol() {
		$status = $this->getStatus();
		return $status['protocol'];
	}

	/**
	 * Check that the remote server is installed and not in maintenance mode
	 *
	 * @return bool
	 */
	public function isActive() {
		$status = $this->getStatus();
		return $status['installed'] && !$status['maintenance'];
	}

	/**
	 * @return array
	 * @throws NotFoundException
	 * @throws \Exception
	 */
	private function getStatus() {
		if ($this->status) {
			return $this->status;
		}
		$key = 'remote/' . $this->url . '/status';
		$httpsKey = 'remote/' . $this->url . '/https';
		$status = $this->cache->get($key);
		if (!$status) {
			$response = $this->downloadStatus('https://' . $this->getUrl() . '/status.php');
			$protocol = 'https';
			if (!$response) {
				if ($status = $this->cache->get($httpsKey)) {
					throw new \Exception('refusing to connect to remote instance(' . $this->url . ') over http that was previously accessible over https');
				}
				$response = $this->downloadStatus('http://' . $this->getUrl() . '/status.php');
				$protocol = 'http';
			} else {
				$this->cache->set($httpsKey, true, 60 * 60 * 24 * 365);
			}
			$status = json_decode($response, true);
			if ($status) {
				$status['protocol'] = $protocol;
			}
			if ($status) {
				$this->cache->set($key, $status, 5 * 60);
				$this->status = $status;
			} else {
				throw new NotFoundException('Remote server not found at address ' . $this->url);
			}
		}
		return $status;
	}

	/**
	 * @param string $url
	 * @return bool|string
	 */
	private function downloadStatus($url) {
		try {
			$request = $this->clientService->newClient()->get($url);
			$content = $request->getBody();

			// IResponse.getBody responds with null|resource if returning a stream response was requested.
			// As that's not the case here, we can just ignore the psalm warning by adding an assertion.
			assert(is_string($content));

			return $content;
		} catch (\Exception $e) {
			return false;
		}
	}
}
