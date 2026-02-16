###########################################################
# ROT - Ring Of Trust SPV service
###########################################################
FROM php:8.3.2-cli-bookworm AS rot

# Minimal runtime deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install shmop

COPY src/rot.php /usr/local/bin

CMD ["php", "/usr/local/bin/rot.php"]
