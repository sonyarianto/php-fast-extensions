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

EXTENSIONS := csv_streamer

BUILD_DIR ?= target/release

.PHONY: all build test bench clean $(addprefix build-,$(EXTENSIONS)) $(addprefix test-,$(EXTENSIONS))

all: build

build: $(addprefix build-,$(EXTENSIONS))
	@echo "All extensions built."

build-%:
	@echo "==> Building $*"
	@cargo build --release --manifest-path $*/Cargo.toml

test: build
	@for ext in $(EXTENSIONS); do \
		echo "==> Testing $$ext"; \
		if [ -f $$ext/tests/generate_csv.php ]; then php $$ext/tests/generate_csv.php; fi; \
		php -d extension=$$ext/$(BUILD_DIR)/lib$$ext.so $$ext/tests/test.php || exit 1; \
	done
	@echo "All tests passed."

bench: build
	@for ext in $(EXTENSIONS); do \
		echo "==> Benchmarking $$ext"; \
		if [ -f $$ext/tests/generate_csv.php ]; then php $$ext/tests/generate_csv.php; fi; \
		php -d extension=$$ext/$(BUILD_DIR)/lib$$ext.so $$ext/tests/bench.php || exit 1; \
	done

clean:
	@for ext in $(EXTENSIONS); do \
		cargo clean --manifest-path $$ext/Cargo.toml; \
	done
	@rm -rf $(BUILD_DIR)
