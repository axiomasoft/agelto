MAKEFLAGS += --no-print-directory

.PHONY: help FORCE
help:
	@$(MAKE) -f docker/Makefile help

%: FORCE
	@$(MAKE) -f docker/Makefile $@

FORCE:
