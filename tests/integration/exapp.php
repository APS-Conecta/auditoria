<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 APS Conecta
// SPDX-License-Identifier: AGPL-3.0-or-later

// A stand-in ExApp for AppAPI's manual-install registration: a heartbeat, an enable, and no /init
// (AppAPI counts a 404 there as a finished init). The ExApps the integration suite registers point here.
header('Content-Type: application/json');
$body = match (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
	'/heartbeat' => '{"status":"ok"}',
	'/enabled' => '{"error":""}',
	default => null,
};
if ($body === null) {
	http_response_code(404);
}
echo $body ?? '{}';
