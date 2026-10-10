# ADR-0014: Production Image Design for Fargate

- **Status:** Accepted
- **Date:** 2026-09-23

## Context

Production image needs to run correctly in both my local dev filesystem and on ECS Fargate filesystem alongside the architecture descision to set readOnlyFilesystem to true. We also need the production image to start as fast as possible

## Decision

- Creating a non-root user (www-data) - limits what attackers can do if they gain code execution
- read-only root filesystem - another security measure that disallows writing to the filesystem
one tmpfs mount - meant for the bootstrap/cache because config:cache needs to run at container start, if it runs at build time the the env gets evaluated and frozen with null values for items like database pass, webhook secret etc...
- multi-stage build - base (contains what is needed for both dev and prod environments), dev (adds Xdebug), prod ( bulds with --no-dev option, only base and prod are built so no debug programs make it into the prod container image), Also good for security because it eliminates possible attack vectors from Xdebug and Pest in the production container
- Opcache with 'validate_timestamps=0" This keep PHP from  checking every file for changes on each request, with this setting it never checks. Compile once on first request and serve from memory after forever (this is in the prod stage only). helps increase start time
- Prod stage is optimized the ECS Faargate immutable filesystem while the dev environment is optimized for faster iterating with a debugger attached. uses the same Dockerfile which is why the multi-stage build exist to manage both of these environemtns properly.


### Alternatives considered

- Leaving readOnlyRootFileSystem turned off and allowing Laravel to write to logs etc, setup would be easier but the tradeoff was leaving an open attack vector
- Using arm64 was considered, but ultimately chose x86 because cross building would require buildx and QEMU emulation, which causes everything to move slower. Speed matters more to me than saving ~20% on compute cost. For a nightly destroy/rebuild project.

## Implementation notes

- Fargate tmpfs has no user/group id options (caused a permission deniedm issue with the storage/logs folder), locally fixed by adding --tmpfs path: uid=82,gid=82, this lead tro the need for no writing files in production. Logs are now sent to the stdout where CloudWatch captures and reads them. No log rotations necessary, storage savings.
- config:cache cant run at build time which is why bootstrap/cache is an unavoidable write.
- .dockerignore context relative and fails silently. I tried to block .md files in the adr folder but had to add the actual filepath before *.md.

## Runtime requirements
Fargate runtime requirements (verified Week 2):
- readonlyRootFilesystem: true
- tmpfs: /var/www/html/bootstrap/cache  (config:cache writes here at startup)
- LOG_CHANNEL=stderr                    (no writable storage/logs)
- SESSION_DRIVER=redis, CACHE_STORE=redis

## Consequences

**Positive** —  This give me a fast startup time and a more secure system alongside a multi stage build built to run in my dev and the immutable Fargate filesystem using the same Dockerfile

**Negative** — Loss of the ease of setup for the laravel app and its ability to write to the filesystem, more expensive using ECS Fargate instead of an x86 system, also need to mount bootstrap/cache so that config:cache can run at container start (avoids having null env values by running at build time).

**Accepted risks** —
When another feature or addition to this system needs to write to the local system it will have to use S3 or the database.
LOG_CHANNEL, SESSION_DRIVER, CACHE_STORE are defined in the task definition instead of the image so there are somethings outside of the image itself that documents how it must be run. Running without these options fails at startup.
Validate timestamps equal to 0, is correct for an immutable image like ECS Fargate but incorrect for anywhere that has a bind mount, if prod stage gets used locally itll serve stale code.
