#!/usr/bin/env python3
"""WSS/WS SIP <-> UDP SIP gateway.

Browsers keep using nginx /wss/. FreeSWITCH sees a normal UDP registration
on 127.0.0.1, so callee INVITEs no longer try a new WebSocket to nginx (503).
"""
from __future__ import annotations

import asyncio
import logging
import os
import re
import socket
import uuid
from concurrent.futures import ThreadPoolExecutor

try:
    import websockets
    from websockets.server import serve
except ImportError:
    raise SystemExit("pip3 install websockets")

FS_HOST = os.environ.get("SKYKIN_FS_SIP_HOST", "127.0.0.1")
FS_PORT = int(os.environ.get("SKYKIN_FS_SIP_PORT", "5060"))
UDP_BIND = os.environ.get("SKYKIN_FS_UDP_BIND", "127.0.0.1")
LISTEN_HOST = os.environ.get("SKYKIN_WS_SIP_HOST", "0.0.0.0")
LISTEN_PORT = int(os.environ.get("SKYKIN_WS_SIP_PORT", "18081"))

log = logging.getLogger("skykin-ws-sip")
VIA_BRANCH_RE = re.compile(r"branch=([^;]+)", re.I)


def parse_sip(raw: str):
    if "\r\n\r\n" in raw:
        head, body = raw.split("\r\n\r\n", 1)
    else:
        head, body = raw, ""
    lines = head.split("\r\n")
    return lines[0], lines[1:], body


def serialize(start: str, headers: list[str], body: str) -> str:
    headers = [h for h in headers if not h.lower().startswith("content-length:")]
    headers.append(f"Content-Length: {len(body.encode('utf-8'))}")
    return start + "\r\n" + "\r\n".join(headers) + "\r\n\r\n" + body


def header_name(h: str) -> str:
    return h.split(":", 1)[0].strip().lower()


def header_val(h: str) -> str:
    return h.split(":", 1)[1].strip() if ":" in h else ""


def _rewrite_contact(val: str, agent: "Agent") -> str:
    val = re.sub(r"@[^;>;\s]+", f"@{agent.host}:{agent.port}", val, count=1)
    val = re.sub(r"transport=wss", "transport=udp", val, flags=re.I)
    val = re.sub(r"transport=ws", "transport=udp", val, flags=re.I)
    return val


def rewrite_to_fs(start: str, headers: list[str], agent: "Agent") -> list[str]:
    is_response = start.startswith("SIP/2.0 ")
    out = []
    via_done = False
    for h in headers:
        name = header_name(h)
        val = header_val(h)
        if name == "via" and not via_done:
            via_done = True
            if is_response and agent.pending_fs_via:
                # Keep FreeSWITCH's original Via so 180/200 match the INVITE.
                out.append(f"Via: {agent.pending_fs_via}")
            else:
                agent.last_via = val
                branch = VIA_BRANCH_RE.search(val)
                br = branch.group(1) if branch else "z9hG4bK" + uuid.uuid4().hex[:12]
                out.append(f"Via: SIP/2.0/UDP {agent.host}:{agent.port};branch={br};rport")
            continue
        if name == "contact":
            if start.startswith("REGISTER"):
                agent.last_contact = val
            out.append(f"Contact: {_rewrite_contact(val, agent)}")
            continue
        out.append(h)
    return out


def webrtc_fix_sdp(body: str) -> str:
    """Chrome needs UDP/TLS/RTP/SAVPF and an audio-only offer."""
    if not body or "m=audio" not in body:
        return body
    body = body.replace("UDP/TLS/RTP/SAVPF", "RTP/SAVPF")
    body = body.replace("RTP/SAVPF", "UDP/TLS/RTP/SAVPF")
    cut = re.search(r"\r\nm=video", body)
    if cut:
        body = body[: cut.start()]
        if not body.endswith("\r\n"):
            body += "\r\n"

    def _mono_fmtp(match: re.Match) -> str:
        line = match.group(0)
        if "minptime" not in line and "useinbandfec" not in line and "stereo" not in line:
            return line
        if "stereo=" in line:
            line = re.sub(r"sprop-stereo=\d+", "sprop-stereo=0", line)
            line = re.sub(r"(?<!sprop-)stereo=\d+", "stereo=0", line)
            if "sprop-stereo=" not in line:
                line += ";sprop-stereo=0"
            if not re.search(r"(?<!sprop-)stereo=", line):
                line += ";stereo=0"
            return line
        return line + ";stereo=0;sprop-stereo=0"

    body = re.sub(r"a=fmtp:\d+ [^\r\n]+", _mono_fmtp, body)
    return body


def rewrite_start_to_ws(start: str, agent: "Agent") -> str:
    if start.startswith("INVITE ") and agent.last_contact:
        m = re.search(r"sip:([^@>\s]+@[^;>\s]+)", agent.last_contact)
        if m:
            uri = m.group(1).split(";")[0] + ";transport=wss"
            return re.sub(r"sip:\S+", "sip:" + uri, start, count=1)
    return start


def rewrite_to_ws(start: str, headers: list[str], agent: "Agent") -> list[str]:
    is_response = start.startswith("SIP/2.0 ")
    if start.startswith("INVITE"):
        for h in headers:
            if header_name(h) == "via":
                agent.pending_fs_via = header_val(h)
                break
    out = []
    via_done = False
    for h in headers:
        name = header_name(h)
        if name == "via" and not via_done:
            via_done = True
            if is_response and agent.last_via:
                out.append(f"Via: {agent.last_via}")
            else:
                val = header_val(h).replace("SIP/2.0/UDP", "SIP/2.0/WSS")
                out.append(f"Via: {val}")
            continue
        if name == "contact" and is_response and agent.last_contact:
            out.append(f"Contact: {agent.last_contact}")
            continue
        out.append(h)
    return out


class Agent:
    def __init__(self, ws):
        self.ws = ws
        self.udp = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.udp.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.udp.bind((UDP_BIND, 0))
        self.udp.setblocking(True)
        self.udp.settimeout(1.0)
        self.host, self.port = self.udp.getsockname()
        self.pool = ThreadPoolExecutor(max_workers=1, thread_name_prefix="sip-udp")
        self.last_via = None
        self.last_contact = None
        self.pending_fs_via = None

    def close(self):
        try:
            self.udp.close()
        except OSError:
            pass
        self.pool.shutdown(wait=False, cancel_futures=True)


agents: set[Agent] = set()


def _recvfrom(sock):
    while True:
        try:
            return sock.recvfrom(65535)
        except socket.timeout:
            continue


async def udp_reader(agent: Agent):
    loop = asyncio.get_running_loop()
    try:
        while True:
            data, addr = await loop.run_in_executor(agent.pool, _recvfrom, agent.udp)
            text = data.decode("utf-8", "replace")
            start, headers, body = parse_sip(text)
            start = rewrite_start_to_ws(start, agent)
            if start.startswith("INVITE ") or (
                start.startswith("SIP/2.0 ") and body.startswith("v=0")
            ):
                body = webrtc_fix_sdp(body)
            out = serialize(start, rewrite_to_ws(start, headers, agent), body)
            log.info("FS %s -> WS %s", addr, start[:60])
            await agent.ws.send(out)
    except (asyncio.CancelledError, ConnectionError, OSError):
        return
    except Exception:
        log.exception("udp_reader")


async def ws_handler(ws):
    agent = Agent(ws)
    agents.add(agent)
    log.info("WS up udp=%s:%s peer=%s", agent.host, agent.port, ws.remote_address)
    reader = asyncio.create_task(udp_reader(agent))
    try:
        async for message in ws:
            if isinstance(message, bytes):
                message = message.decode("utf-8", "replace")
            start, headers, body = parse_sip(message)
            if body.startswith("v=0"):
                body = webrtc_fix_sdp(body)
            out = serialize(start, rewrite_to_fs(start, headers, agent), body)
            agent.udp.sendto(out.encode("utf-8"), (FS_HOST, FS_PORT))
            log.info("WS -> FS %s udp=%s:%s", start[:60], agent.host, agent.port)
    except websockets.ConnectionClosed:
        pass
    finally:
        reader.cancel()
        agent.close()
        agents.discard(agent)
        log.info("WS down udp=%s:%s", agent.host, agent.port)


async def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    log.info("listening ws://%s:%s -> sip udp %s:%s", LISTEN_HOST, LISTEN_PORT, FS_HOST, FS_PORT)
    async with serve(ws_handler, LISTEN_HOST, LISTEN_PORT, subprotocols=["sip"], max_size=2**20):
        await asyncio.Future()


if __name__ == "__main__":
    asyncio.run(main())
