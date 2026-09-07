FROM postgres:18

# pgvector only — postgis stays out for now, PropertyService.php still uses
# geography columns so re-adding it later needs that code path revisited too.
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-18-pgvector \
    && rm -rf /var/lib/apt/lists/*
