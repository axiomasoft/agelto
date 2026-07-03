MAKEFLAGS += --no-print-directory

.PHONY: help
help:
	@echo "Docker: цели из docker/Makefile (make migrate, make up, …)."
	@$(MAKE) -f docker/Makefile help

%:
	@$(MAKE) -f docker/Makefile $@
