"""An optional virus scan with ClamAV, over clamd's INSTREAM command.

No ClamAV, or one that does not answer, is "unavailable": the upload is still
accepted after the cheap checks and marked not_scanned. That is the install
owner's choice (see the design), so this never raises.
"""
import asyncio
import struct
from dataclasses import dataclass

CHUNK = 64 * 1024


@dataclass(frozen=True)
class Scan:
    status: str  # clean, infected, unavailable
    name: str = ""


async def scan(data: bytes, host: str, port: int, timeout: float = 15) -> Scan:
    if not host:
        return Scan("unavailable")
    try:
        return await asyncio.wait_for(_instream(data, host, port), timeout)
    except Exception as error:
        print(f"[Files] ClamAV unavailable: {error}")
        return Scan("unavailable")


async def _instream(data: bytes, host: str, port: int) -> Scan:
    reader, writer = await asyncio.open_connection(host, port)
    try:
        writer.write(b"zINSTREAM\x00")
        for start in range(0, len(data), CHUNK):
            piece = data[start:start + CHUNK]
            writer.write(struct.pack(">I", len(piece)) + piece)
        writer.write(struct.pack(">I", 0))
        await writer.drain()
        reply = (await reader.read(4096)).rstrip(b"\x00").decode("utf-8", "replace").strip()
    finally:
        writer.close()

    if reply.endswith("OK"):
        return Scan("clean")
    if reply.endswith("FOUND"):
        return Scan("infected", reply.split(":", 1)[-1].rsplit(" ", 1)[0].strip())
    print(f"[Files] ClamAV answered something unexpected: {reply[:200]}")
    return Scan("unavailable")
