# Build and test all PHP extensions in this repository.
#
# Each extension lives in its own directory and is an independent Rust
# cdylib crate named `rust_<ext>` (producing `lib<ext>.so`).
#
# Usage:
#   make build   # build all extensions (release)
#   make test    # build + run each extension's PHP test suite
#   make bench   # run each extension's PHP benchmark
#   make clean

EXTENSIONS := csv_streamer excel_streamer json_streamer

BUILD_DIR ?= target/release

.PHONY: all build test bench clean

all: build

build: $(addprefix build-,$(EXTENSIONS))
	@echo "All extensions built."

build-%:
	@echo "==> Building $*"
	@cargo build --release --manifest-path $*/Cargo.toml

test: build
	@for ext in $(EXTENSIONS); do \
		echo "==> Testing $$ext"; \
		for gen in $$ext/tests/generate_*.php; do \
			[ -f "$$gen" ] && php "$$gen"; \
		done; \
		php -d extension=$$ext/$(BUILD_DIR)/lib$$ext.so $$ext/tests/test.php || exit 1; \
		for stub in $$ext/stubs/*.php; do \
			[ -f "$$stub" ] || continue; \
			cls=$$(grep -oP '^\s*(abstract\s+)?final\s+class\s+\K\w+|^\s*class\s+\K\w+' "$$stub" | head -1); \
			php -d extension=$$ext/$(BUILD_DIR)/lib$$ext.so $$ext/tests/check_stubs.php "$$stub" "$$cls" || exit 1; \
		done; \
	done
	@echo "All tests passed."

bench: build
	@for ext in $(EXTENSIONS); do \
		echo "==> Benchmarking $$ext"; \
		for gen in $$ext/tests/generate_*.php; do \
			[ -f "$$gen" ] && php "$$gen"; \
		done; \
		php -d extension=$$ext/$(BUILD_DIR)/lib$$ext.so $$ext/tests/bench.php || exit 1; \
	done

clean:
	@for ext in $(EXTENSIONS); do \
		cargo clean --manifest-path $$ext/Cargo.toml; \
	done
	@rm -rf $(BUILD_DIR)
