# DOCO_VERBS dispatch to doco.sh; status dispatches to dockcheck.sh. VERBS is
# the union - it drives the typo guard, argument stripping and .PHONY, none of
# which care which script a verb ends up calling.
DOCO_VERBS := restart stop start mounts
VERBS := $(DOCO_VERBS) status
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
# `ifdef` is true for any non-empty value, so a bare `ifdef DRY` would make
# DRY=0 perform a dry run - which reads as "off" to everyone. Treat the usual
# falsey spellings as off.
DOCO_FLAGS :=
ifneq ($(filter-out 0 false no off,$(DRY)),)
DOCO_FLAGS += --dry-run
endif
ifneq ($(filter-out 0 false no off,$(KIND)),)
DOCO_FLAGS += --$(KIND)
endif

# status only reads, so neither flag means anything to it. Accepting them
# silently would imply a dry run had been honoured when nothing was ever going
# to change - same reasoning as the unknown-verb guard above.
ifeq ($(FIRST),status)
ifneq ($(DOCO_FLAGS),)
$(error DRY= and KIND= do not apply to 'status' - it only reads)
endif
endif

.PHONY: help $(VERBS)

help:
	@echo "usage: make {restart|stop|start|mounts} <stack-or-container> [DRY=1] [KIND=stack|container]"
	@echo "       make status [<stack-or-container>...]"
	@echo ""
	@echo "  make restart media               restart a whole stack"
	@echo "  make restart jellyfin            restart a single container"
	@echo "  make stop media ai               several at once"
	@echo "  make mounts immich_server        list mounts, flag empty bind sources"
	@echo "  make restart media DRY=1         resolve only, change nothing"
	@echo "  make restart media KIND=stack    force the stack reading of an ambiguous name"
	@echo ""
	@echo "  make status                      health of every container in every stack"
	@echo "  make status media                one stack"
	@echo "  make status jellyfin             one container"
	@echo ""
	@echo "status exits 1 when anything is unhealthy, so Make reports 'Error 1'"
	@echo "on top of the table. DRY= and KIND= apply to the doco.sh verbs only."
	@echo ""
	@echo "DRY=0 / KIND=0 count as off. Full options: ./scripts/doco.sh --help"
	@echo "                             and:          ./scripts/dockcheck.sh --help"

$(DOCO_VERBS):
	@./scripts/doco.sh $@ $(ARGS) $(DOCO_FLAGS)

status:
	@./scripts/dockcheck.sh $(ARGS)

# Absorbs bare names so Make does not try to build them as targets.
%:
	@:
