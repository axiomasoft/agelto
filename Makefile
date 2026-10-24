MAKEFLAGS += --no-print-directory
.DEFAULT_GOAL := help

CORE_TARGETS := core-test-up core-test-env core-test-reset core-test-install core-test-unit core-test-execution core-test-observability core-test-network core-test-platforms core-test-acquisition core-test-documents core-test-ai core-test-concurrency core-test-e2e core-test-faults core-test-load core-quality core-test-regression core-test-report core-test-down
.PHONY: $(CORE_TARGETS)
$(CORE_TARGETS):
	@python3 docker/tests/process-core/rig.py $@

.PHONY: help FORCE
help:
	@$(MAKE) -f docker/Makefile help

%: FORCE
	@$(MAKE) -f docker/Makefile $@

FORCE:
