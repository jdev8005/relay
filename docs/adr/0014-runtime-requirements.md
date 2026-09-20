Fargate runtime requirements (verified Week 2):
- readonlyRootFilesystem: true
- tmpfs: /var/www/html/bootstrap/cache  (config:cache writes here at startup)
- LOG_CHANNEL=stderr                    (no writable storage/logs)
- SESSION_DRIVER=redis, CACHE_STORE=redis

Reasons for building arm64 for Fargate instead x86:
you considered arm64, the saving is real, and you deferred it because build iteration speed matters more than compute cost while the image changes daily.
