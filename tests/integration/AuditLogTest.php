<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 APS Conecta
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Auditoria\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The route over HTTP on the dev instance (compose.dev.yaml), read back from admin_audit's
 * audit.log. AppAPI's own authentication decides who is calling. Runs inside the nextcloud container.
 */
final class AuditLogTest extends TestCase {
	private const ROUTE = 'http://localhost/ocs/v2.php/apps/auditoria/api/v1/escrituras';
	private const AUDIT_LOG = '/var/www/html/data/audit.log';
	private const EXAPP_VERSION = '1.0.0';
	/** The throwaway instance's ExApps: their test-only secrets and the stand-in's port each answers on. */
	private const EXAPPS = ['mcp_connector' => ['connector-test-secret', 9000], 'context_agent' => ['context-agent-test-secret', 9001]];
	private const PASSWORD = 'alice-test-password';

	public static function setUpBeforeClass(): void {
		// A rerun finds the daemon and the user already there, and no ExApp on a first run.
		self::occ(['app_api:daemon:register', 'manual_install', 'Manual install', 'manual-install', 'http', 'exapp', 'http://localhost'], check: false);
		foreach (self::EXAPPS as $appId => [$secret, $port]) {
			self::occ(['app_api:app:unregister', $appId, '--silent', '--force'], check: false);
			self::occ(['app_api:app:register', $appId, 'manual_install', '--wait-finish', '--json-info=' . json_encode([
				'id' => $appId,
				'name' => $appId,
				'daemon_config_name' => 'manual_install',
				'version' => self::EXAPP_VERSION,
				'secret' => $secret,
				'port' => $port,
			])]);
		}
		self::occ(['user:add', '--password-from-env', 'alice'], check: false, env: ['OC_PASS' => self::PASSWORD]);
	}

	public function testEachConfirmedWriteLeavesExactlyOneEntryWithTheUserTheToolAndTheObjectId(): void {
		$offset = self::auditLogSize();

		$this->assertSame(200, self::post(self::signedBy('mcp_connector', 'alice'), ['tool' => 'notes_create_note', 'objectId' => '42']));

		$entries = self::entriesSince($offset);
		$this->assertCount(1, $entries);
		$this->assertSame('alice', $entries[0]['user']);
		$this->assertSame('AI write confirmed: user "alice", tool "notes_create_note", object "42"', $entries[0]['message']);
	}

	public function testAFieldNamingAnotherUserChangesNothing(): void {
		$offset = self::auditLogSize();

		$this->assertSame(200, self::post(self::signedBy('mcp_connector', 'alice'), [
			'tool' => 'deck_create_card', 'objectId' => '7', 'user' => 'bob', 'userId' => 'bob',
		]));

		$entries = self::entriesSince($offset);
		$this->assertCount(1, $entries);
		$this->assertSame('alice', $entries[0]['user']);
		$this->assertSame('AI write confirmed: user "alice", tool "deck_create_card", object "7"', $entries[0]['message']);
	}

	/** @return array<string, array{list<string>, int}> */
	public static function otherCallers(): array {
		return [
			'another ExApp, signing as alice' => [self::signedBy('context_agent', 'alice'), 403],
			"alice's own login, sending the connector's id" => [['Authorization: Basic ' . base64_encode('alice:' . self::PASSWORD), 'EX-APP-ID: mcp_connector'], 403],
			'the connector, signing as no user' => [self::signedBy('mcp_connector', ''), 401],
		];
	}

	/** @param list<string> $headers */
	#[DataProvider('otherCallers')]
	public function testAnyOtherCallerIsRefusedAndLeavesNoEntry(array $headers, int $status): void {
		$offset = self::auditLogSize();

		$this->assertSame($status, self::post($headers, ['tool' => 'notes_create_note', 'objectId' => '42']));

		// A user's own login leaves admin_audit's login lines; no AI write may follow it.
		$aiWrites = array_filter(self::entriesSince($offset), static fn (array $entry): bool => str_starts_with($entry['message'], 'AI write'));
		$this->assertSame([], $aiWrites);
	}

	/** @return list<string> AppAPI's headers for $appId calling as $user */
	private static function signedBy(string $appId, string $user): array {
		return [
			'EX-APP-ID: ' . $appId,
			'EX-APP-VERSION: ' . self::EXAPP_VERSION,
			'AUTHORIZATION-APP-API: ' . base64_encode($user . ':' . self::EXAPPS[$appId][0]),
		];
	}

	/**
	 * @param list<string> $headers
	 * @param array<string, string> $fields
	 * @return int the HTTP status
	 */
	private static function post(array $headers, array $fields): int {
		$curl = curl_init(self::ROUTE);
		curl_setopt_array($curl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($fields),
			CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Content-Type: application/json', ...$headers],
			CURLOPT_RETURNTRANSFER => true,
		]);
		curl_exec($curl);
		return curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
	}

	private static function auditLogSize(): int {
		clearstatcache();
		return is_file(self::AUDIT_LOG) ? filesize(self::AUDIT_LOG) : 0;
	}

	/** @return list<array<string, mixed>> the entries audit.log gained after $offset bytes */
	private static function entriesSince(int $offset): array {
		$tail = self::auditLogSize() > $offset ? (string)file_get_contents(self::AUDIT_LOG, offset: $offset) : '';
		return array_map(
			static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
			array_values(array_filter(explode("\n", $tail))),
		);
	}

	/**
	 * @param list<string> $args
	 * @param array<string, string> $env
	 */
	private static function occ(array $args, bool $check = true, array $env = []): void {
		$process = proc_open([PHP_BINARY, '/var/www/html/occ', '--no-interaction', ...$args], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env + getenv());
		$output = stream_get_contents($pipes[1]);
		if (proc_close($process) !== 0 && $check) {
			throw new \RuntimeException('occ ' . $args[0] . ' failed: ' . $output);
		}
	}
}
