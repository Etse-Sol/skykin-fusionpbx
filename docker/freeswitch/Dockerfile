# FreeSWITCH for full-stack SkyKin Docker.
FROM safarov/freeswitch:latest

COPY entrypoint.sh /usr/local/bin/skykin-fs-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/skykin-fs-entrypoint.sh \
    && chmod +x /usr/local/bin/skykin-fs-entrypoint.sh \
    && head -1 /usr/local/bin/skykin-fs-entrypoint.sh | od -c | head -2

# Use /bin/sh explicitly so Windows CRLF or missing bash cannot break startup.
ENTRYPOINT ["/bin/sh", "/usr/local/bin/skykin-fs-entrypoint.sh"]

EXPOSE 5060/tcp 5060/udp 5080/tcp 5080/udp 5066/tcp 8021/tcp 16384-16584/udp
