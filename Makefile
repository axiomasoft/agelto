MAKEFLAGS += --no-print-directory

.PHONY: help
help:
	@$(MAKE) -f docker/Makefile help

%:
	@$(MAKE) -f docker/Makefile $@
