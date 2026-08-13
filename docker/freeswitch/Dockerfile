# FreeSWITCH for full-stack SkyKin Docker.
FROM safarov/freeswitch:latest

# The base image ships no sound files whatsoever, so vanilla's
# hold_music=local_stream://moh resolves to nothing. A caller waiting in a queue
# then receives no media at all, which also starves record_session: every queue
# recording came out as a bare 44-byte WAV header or a couple of seconds long,
# while direct extension calls recorded fine. The 8 kHz pack is ~15 MB and
# FreeSWITCH resamples it up to whatever rate a call negotiates.
# Build without network access still succeeds; the entrypoint then falls back to
# a generated tone so queue media is never silent.
ARG MOH_URL=https://files.freeswitch.org/releases/sounds/freeswitch-sounds-music-8000-1.0.52.tar.gz
# Downloaded into the sounds directory rather than /tmp, which this base image
# does not ship.
RUN set -eu; \
    mkdir -p /usr/share/freeswitch/sounds; \
    if wget -q -O /usr/share/freeswitch/sounds/moh.tar.gz "$MOH_URL"; then \
      tar -xzf /usr/share/freeswitch/sounds/moh.tar.gz -C /usr/share/freeswitch/sounds; \
      rm -f /usr/share/freeswitch/sounds/moh.tar.gz; \
      find /usr/share/freeswitch/sounds/music -name '*.wav' | wc -l; \
    else \
      rm -f /usr/share/freeswitch/sounds/moh.tar.gz; \
      echo "WARNING: could not fetch hold music from $MOH_URL"; \
    fi

COPY entrypoint.sh /usr/local/bin/skykin-fs-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/skykin-fs-entrypoint.sh \
    && chmod +x /usr/local/bin/skykin-fs-entrypoint.sh \
    && head -1 /usr/local/bin/skykin-fs-entrypoint.sh | od -c | head -2

# Use /bin/sh explicitly so Windows CRLF or missing bash cannot break startup.
ENTRYPOINT ["/bin/sh", "/usr/local/bin/skykin-fs-entrypoint.sh"]

EXPOSE 5060/tcp 5060/udp 5066/tcp 8021/tcp 16384-16584/udp
