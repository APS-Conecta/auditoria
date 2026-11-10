<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 APS Conecta
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Auditoria\Tests\Unit\Controller;

use OCA\Auditoria\Controller\AuditController;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\ISession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditControllerTest extends TestCase {
	/** @var list<Event> */
	private array $dispatched = [];

	public function testTheConnectorRecordsOneEntryWithTheUserTheToolAndTheObjectId(): void {
		$this->controller('mcp_connector', appApi: true)->record('notes_create_note', '42');

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(CriticalActionPerformedEvent::class, $event);
		$this->assertSame(
			'AI write confirmed: user "alice", tool "notes_create_note", object "42"',
			vsprintf($event->getLogMessage(), array_values($event->getParameters())),
		);
	}

	/** @return array<string, array{string, ?bool}> */
	public static function otherCallers(): array {
		return [
			'another ExApp' => ['context_agent', true],
			"a user's own login sending the connector's id" => ['mcp_connector', null],
		];
	}

	#[DataProvider('otherCallers')]
	public function testAnyOtherCallerIsRefusedAndRecordsNothing(string $exAppId, ?bool $appApi): void {
		try {
			$this->controller($exAppId, $appApi)->record('notes_create_note', '42');
			$this->fail('the call must be refused');
		} catch (OCSForbiddenException) {
		}

		$this->assertSame([], $this->dispatched);
	}

	/** @return array<string, array{string, string}> */
	public static function incompleteCalls(): array {
		return [
			'no tool' => ['', '42'],
			'no object id' => ['notes_create_note', ''],
		];
	}

	#[DataProvider('incompleteCalls')]
	public function testACallWithoutTheToolOrTheObjectIdIsRefusedAndRecordsNothing(string $tool, string $objectId): void {
		try {
			$this->controller('mcp_connector', appApi: true)->record($tool, $objectId);
			$this->fail('the call must be refused');
		} catch (OCSBadRequestException) {
		}

		$this->assertSame([], $this->dispatched);
	}

	private function controller(string $exAppId, ?bool $appApi, string $userId = 'alice'): AuditController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['EX-APP-ID', $exAppId]]);
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnMap([['app_api', $appApi]]);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->dispatched[] = $event;
		});

		return new AuditController('auditoria', $request, $session, $dispatcher, $userId);
	}
}
