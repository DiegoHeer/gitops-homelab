VERBS := restart stop start mounts
FIRST := $(firstword $(MAKECMDGOALS))

# Make needs a catch-all rule to accept bare names as positional arguments,
# but a bare catch-all would turn any typo into a silent no-op. This parse-time
# guard rejects an unknown first goal before the catch-all can swallow it.
ifneq ($(FIRST),)
ifeq ($(filter $(FIRST),$(VERBS) help),)
$(error unknown command '$(FIRST)' - try: make help)
endif
endif

ARGS := $(filter-out $(VERBS),$(MAKECMDGOALS))

# doco.sh's long options cannot be passed through Make: --dry-run collides with
# Make's own -n/--dry-run and would be swallowed silently, and --stack /
# --container make Make abort with "unrecognized option". Expose them as
# variables instead. Use ./scripts/doco.sh directly if you prefer the flags.
DOCO_FLAGS :=
ifdef DRY
DOCO_FLAGS += --dry-run
endif
ifdef KIND
DOCO_FLAGS += --$(KIND)
endif

.PHONY: help $(VERBS)

help:
	@echo "usage: make {restart|stop|start|mounts} <stack-or-container> [DRY=1] [KIND=stack|container]"
	@echo ""
	@echo "  make restart media               restart a whole stack"
	@echo "  make restart jellyfin            restart a single container"
	@echo "  make stop media ai               several at once"
	@echo "  make mounts immich_server        list mounts, flag empty bind sources"
	@echo "  make restart media DRY=1         resolve only, change nothing"
	@echo "  make restart media KIND=stack    force the stack reading of an ambiguous name"
	@echo ""
	@echo "Equivalent to ./scripts/doco.sh <verb> <name> [--dry-run] [--stack|--container]."

$(VERBS):
	@./scripts/doco.sh $@ $(ARGS) $(DOCO_FLAGS)

# Absorbs bare names so Make does not try to build them as targets.
%:
	@:
