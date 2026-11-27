FROM postgres:19@sha256:4ea94de574c17f0372722806c949757050fbb1cd7648410fe45d6bba275cc00e

ARG SOURCE_SHA=dev

# pg_partman has no Alpine package (PGDG publishes apt repositories only), so the Debian
# variant is used: its PGDG repository is preconfigured.
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-19-partman \
    && rm -rf /var/lib/apt/lists/*

# Since PostgreSQL 18 the official image defaults PGDATA to /var/lib/postgresql/<major>/docker
# and refuses to start when /var/lib/postgresql/data is a mount. Every Compose file and the
# backup tooling mount the data volume at /var/lib/postgresql/data, so keep that location.
ENV PGDATA=/var/lib/postgresql/data

LABEL org.opencontainers.image.revision="${SOURCE_SHA}"
