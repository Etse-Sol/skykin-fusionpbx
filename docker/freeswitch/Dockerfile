# FreeSWITCH for full-stack SkyKin Docker.
FROM safarov/freeswitch:latest

COPY entrypoint.sh /usr/local/bin/skykin-fs-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/skykin-fs-entrypoint.sh \
    && chmod +x /usr/local/bin/skykin-fs-entrypoint.sh

# SIP / WS / ESL / lab RTP window (use host networking in production for full RTP)
EXPOSE 5060/tcp 5060/udp 5066/tcp 8021/tcp 16384-16584/udp

ENTRYPOINT ["/usr/local/bin/skykin-fs-entrypoint.sh"]
