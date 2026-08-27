# syntax=docker/dockerfile:1

# PostgreSQL with pgvector, built ON TOP OF the image this stack already runs.
#
# WHY THIS FILE EXISTS AT ALL, INSTEAD OF `image: pgvector/pgvector:pg18`
# ──────────────────────────────────────────────────────────────────────
# The published pgvector image is Debian, and the shipped one is Alpine. Every
# byte of PGDATA on an existing install was written by a musl-linked server, and
# swapping to a glibc one under it is a silent-data-corruption event. That was
# tested here rather than reasoned about:
#
#   The swap returns wrong rows from an index scan, with an empty log. musl
#   records no collation version, so `datcollversion` is NULL, so Postgres's
#   mismatch check never fires. 44 collation-dependent indexes — 18 of them
#   UNIQUE — are silently walked with the wrong comparator.
#
# docs/install/upgrading.md says "Starting the new image IS running it": this
# stack applies migrations on boot, so an operator who pulls a tag has already
# run it by the time anything could warn them. A libc change is therefore not
# something that may ever ride in on a `docker compose pull`.
#
# So: same base image, same musl, same PostgreSQL binaries, same locale data,
# same on-disk format. The only difference between this image and
# `postgres:${POSTGRES_VERSION}-alpine` is three added files — vector.so and its
# two extension scripts. Nothing that reads or compares existing data changes,
# so there is no index to rebuild and no dump-and-restore to schedule.
#
# WHY IT CAN BE BUILT AT ALL
# ──────────────────────────
# `postgres:18-alpine` ships the server headers (`pg_config`, and
# /usr/local/include/postgresql/server/postgres.h), which the Debian image does
# not. That is what makes an in-image PGXS build possible without assembling a
# PostgreSQL source tree.

# Kept in step with compose's own ${POSTGRES_VERSION:-18} and passed in from
# there, so `POSTGRES_VERSION=19 docker compose ... up` still moves both the
# builder and the runtime together. Two stages, one ARG, no way to compile
# against 18's headers and load into 19.
ARG POSTGRES_VERSION=18

# v0.8.6 and NOT v0.8.1, which is the earliest tag that compiles against
# PostgreSQL 18 — v0.8.0 fails in src/hnswvacuum.c because vacuum_delay_point()
# gained a `bool is_analyze` parameter in 18, and in src/ivfbuild.c on
# TupleDescData.attrs. v0.8.1 is where "support for Postgres 18" landed, and it
# builds and runs here.
#
# The three releases after it are the reason this is not pinned to that:
#
#   0.8.3  fixed possible INDEX CORRUPTION with HNSW vacuuming
#   0.8.4  fixed `hnsw graph not repaired`, and inserts erroring during a vacuum
#   0.8.6  fixed a buffer overflow in the IVFFlat build on 32-bit
#
# HNSW vacuuming is not an exotic path here: message_embedding has ON DELETE
# CASCADE from `message`, and mail is deleted in bulk, so autovacuum reaches
# that index constantly. Shipping the earliest version that merely compiles
# would mean shipping two known index-corruption bugs into the one place this
# project refuses to take that risk.
ARG PGVECTOR_VERSION=v0.8.6

FROM postgres:${POSTGRES_VERSION}-alpine AS build

ARG PGVECTOR_VERSION

# build-base is gcc, musl-dev, make. No clang or llvm: PGXS would use them to
# emit LLVM bitcode for JIT inlining, which costs ~1 GB in this stage and buys
# nothing — JIT inlining does not apply to an index scan, and a missing .bc file
# makes the JIT skip inlining silently rather than fail.
#
# `git` rather than a release tarball so the tag is verifiable by name and the
# clone is one layer; --depth 1 keeps it to the one commit.
RUN set -eux; \
	apk add --no-cache build-base git; \
	git clone --depth 1 --branch "${PGVECTOR_VERSION}" https://github.com/pgvector/pgvector.git /tmp/pgvector; \
	cd /tmp/pgvector; \
	# OPTFLAGS="" is NOT optional and NOT a tuning choice. pgvector's Makefile
	# defaults it to -march=native on x86_64, which bakes the BUILD machine's
	# instruction set into vector.so. An image built on a runner with AVX-512
	# and pulled onto a host without it does not degrade — the backend takes
	# SIGILL the first time a distance is computed, which surfaces as the
	# database connection closing mid-query with nothing in the log.
	make OPTFLAGS="" with_llvm=no -j"$(nproc)"; \
	make OPTFLAGS="" with_llvm=no install

FROM postgres:${POSTGRES_VERSION}-alpine

# Three files, copied out of the builder so the toolchain does not ship. The
# runtime image is `postgres:${POSTGRES_VERSION}-alpine` plus these and nothing
# else — which is what makes the swap safe on a live data volume.
COPY --from=build /usr/local/lib/postgresql/vector.so /usr/local/lib/postgresql/vector.so
COPY --from=build /usr/local/share/postgresql/extension/vector.control /usr/local/share/postgresql/extension/vector.control
COPY --from=build /usr/local/share/postgresql/extension/vector--*.sql /usr/local/share/postgresql/extension/

# Proves at BUILD time that the .so loads into this exact runtime image, rather
# than at 3am on somebody's server. A missing symbol, a headers/binary mismatch
# or an OPTFLAGS slip all fail here.
RUN set -eux; \
	initdb_dir="$(mktemp -d)"; \
	chown postgres "$initdb_dir"; \
	su postgres -c "initdb -D '$initdb_dir' -A trust --no-sync -E UTF8" >/dev/null; \
	su postgres -c "pg_ctl -D '$initdb_dir' -o '-c listen_addresses= -k /tmp' -w start" >/dev/null; \
	su postgres -c "psql -h /tmp -d postgres -v ON_ERROR_STOP=1 -c 'CREATE EXTENSION vector' -c \"SELECT '[1,2,3]'::vector\""; \
	su postgres -c "pg_ctl -D '$initdb_dir' -m immediate -w stop" >/dev/null; \
	rm -rf "$initdb_dir"
