FROM postgres:19@sha256:4ea94de574c17f0372722806c949757050fbb1cd7648410fe45d6bba275cc00e

ARG SOURCE_SHA=dev

# pg_partman has no Alpine package (PGDG publishes apt repositories only), so the Debian
# variant is used: its PGDG repository is preconfigured.
# `apt-get upgrade` takes Debian security fixes released after the pinned base digest.
RUN apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends postgresql-19-partman \
    && rm -rf /var/lib/apt/lists/*

# PGDATA keeps the PostgreSQL 18+ image default, /var/lib/postgresql/<major>/docker. Compose
# mounts the volume at /var/lib/postgresql (the image's declared VOLUME), so a future major
# upgrade can run pg_upgrade --link inside one volume. A volume that still holds a pre-18
# cluster at its root makes the entrypoint refuse to start instead of initialising an empty one.

LABEL org.opencontainers.image.revision="${SOURCE_SHA}"
