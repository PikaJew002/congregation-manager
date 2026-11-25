FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --ignore-platform-reqs

FROM php:8.3-cli-alpine
RUN apk add --no-cache bash ffmpeg ca-certificates tini
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY hls_package.sh ./hls_package.sh
RUN chmod +x ./hls_package.sh && mkdir -p /work

ENV HLS_SCRIPT=/app/hls_package.sh \
    WORK_DIR=/work

EXPOSE 8080
# tini as PID 1 so SIGTERM actually stops the server.
ENTRYPOINT ["/sbin/tini", "--"]
# One request at a time: the built-in server is single-threaded on purpose.
CMD ["php", "-d", "max_execution_time=0", "-d", "memory_limit=512M", "-S", "0.0.0.0:8080", "/app/src/server.php"]
