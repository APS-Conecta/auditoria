# SPDX-FileCopyrightText: 2026 APS Conecta
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Local gate, the same targets ci.yml runs:
#   `make test`             unit suite in a bare PHP container, no Nextcloud
#   `make instance-up`      a throwaway Nextcloud 34 with admin_audit and AppAPI (compose.dev.yaml)
#   `make test-integration` the route against that instance, read back from audit.log
#   `make instance-clean`   remove the instance and its volumes

IMG := auditoria-test
DOCKER := docker run --rm -v "$(CURDIR)":/app -w /app $(IMG)
COMPOSE := docker compose -f compose.dev.yaml
OCC := $(COMPOSE) exec -T --user www-data nextcloud php occ

.PHONY: build-test-image deps test instance-up test-integration instance-clean

build-test-image:
	docker build -q -f Dockerfile.test -t $(IMG) . >/dev/null

# Vendor lands through the container, so no host PHP is needed.
deps: build-test-image
	$(DOCKER) composer install --no-interaction --no-progress

test: deps
	$(DOCKER) vendor/bin/phpunit --configuration phpunit.xml --testdox

instance-up:
	$(COMPOSE) up -d --wait
	$(OCC) app:enable app_api admin_audit auditoria
	# admin_audit writes at info, below the default warn level: let its lines through, and only its.
	$(OCC) config:system:set log.condition apps 0 --value=admin_audit
	# Restart after enabling: the web workers cache the routes and the enabled apps in APCu, which
	# occ cannot clear (estadistica's instance-up).
	$(COMPOSE) restart nextcloud
	$(COMPOSE) up -d --wait

# Needs `make deps` and `make instance-up` first. It registers its own ExApps and user. No
# --testdox: it hides an error in setUpBeforeClass.
test-integration:
	$(COMPOSE) exec -T --user www-data -w /var/www/html/custom_apps/auditoria nextcloud \
		php vendor/bin/phpunit --configuration phpunit.integration.xml

instance-clean:
	$(COMPOSE) down -v
