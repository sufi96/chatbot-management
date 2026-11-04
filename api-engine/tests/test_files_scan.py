"""The optional ClamAV scan, against a fake clamd speaking INSTREAM."""
import asyncio
import struct

from files import scan


async def fake_clamd(reply: bytes):
    """A one-shot clamd: reads the INSTREAM upload, answers with reply. Returns (server, port, received)."""
    received = bytearray()

    async def handle(reader, writer):
        assert await reader.readexactly(10) == b"zINSTREAM\x00"
        while True:
            size = struct.unpack(">I", await reader.readexactly(4))[0]
            if size == 0:
                break
            received.extend(await reader.readexactly(size))
        writer.write(reply)
        await writer.drain()
        writer.close()

    server = await asyncio.start_server(handle, "127.0.0.1", 0)
    return server, server.sockets[0].getsockname()[1], received


async def test_a_clean_file():
    server, port, received = await fake_clamd(b"stream: OK\x00")
    async with server:
        got = await scan.scan(b"hello world", "127.0.0.1", port)

    assert got.status == "clean" and bytes(received) == b"hello world"


async def test_an_infected_file_names_what_was_found():
    server, port, _ = await fake_clamd(b"stream: Eicar-Signature FOUND\x00")
    async with server:
        got = await scan.scan(b"X5O!P%@AP", "127.0.0.1", port)

    assert got.status == "infected" and got.name == "Eicar-Signature"


async def test_nothing_listening_is_unavailable():
    got = await scan.scan(b"x", "127.0.0.1", 9, timeout=2)

    assert got.status == "unavailable"


async def test_no_host_set_is_unavailable_without_connecting():
    assert (await scan.scan(b"x", "", 3310)).status == "unavailable"
