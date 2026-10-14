FROM postgres:17.9-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends restic cron curl ca-certificates && rm -rf /var/lib/apt/lists/*
RUN ln -sf /usr/share/zoneinfo/Europe/Warsaw /etc/localtime && echo Europe/Warsaw > /etc/timezone
COPY run-backup.sh /usr/local/bin/run-backup
COPY entrypoint.sh /usr/local/bin/start-backup
RUN chmod 0755 /usr/local/bin/run-backup /usr/local/bin/start-backup
ENTRYPOINT ["/usr/local/bin/start-backup"]
