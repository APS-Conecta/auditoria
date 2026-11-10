<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 APS Conecta
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Auditoria\Controller;

use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\ISession;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * The suite AI's confirmed writes, into admin_audit's audit.log (gestion-aio#106).
 */
final class AuditController extends OCSController {
	/** The suite's MCP connector, as its appinfo declares it. */
	private const CONNECTOR = 'mcp_connector';

	public function __construct(
		string $appName,
		IRequest $request,
		private ISession $session,
		private IEventDispatcher $dispatcher,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Record one write the user confirmed: the tool's name and the object's id, never its content.
	 *
	 * @param string $tool The connector tool that wrote
	 * @param string $objectId The id of the object it wrote
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/escrituras')]
	public function record(string $tool = '', string $objectId = ''): DataResponse {
		// Core's own test for the calling ExApp (webhook_listeners): AppAPI sets app_api only after
		// it checked EX-APP-ID's secret, so the header names the ExApp that signed this request.
		if ($this->session->get('app_api') !== true || $this->request->getHeader('EX-APP-ID') !== self::CONNECTOR) {
			throw new OCSForbiddenException('Solo el conector de la IA de la suite registra escrituras.');
		}
		if ($tool === '' || $objectId === '') {
			throw new OCSBadRequestException('Falta la herramienta o el identificador del objeto.');
		}
		$this->dispatcher->dispatchTyped(new CriticalActionPerformedEvent(
			'AI write confirmed: user "%s", tool "%s", object "%s"',
			['user' => $this->userId, 'tool' => $tool, 'objectId' => $objectId],
		));
		return new DataResponse();
	}
}
