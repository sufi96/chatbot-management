"""Where the bytes of chat files live.

One small interface, two backends. Local disk is the default; S3 or MinIO
(MinIO speaks the S3 protocol, so one client covers both) is chosen with
FILE_STORAGE=s3 and the S3_* settings, with no code change. Rows in chat_files
name a storage_key; nothing else about a file's location leaks out of here.
"""
import asyncio
from pathlib import Path

from config import settings


class LocalStorage:
    def __init__(self, root):
        self.root = Path(root).resolve()

    def _path(self, key: str) -> Path:
        path = (self.root / key).resolve()
        if self.root not in path.parents:
            raise ValueError(f"storage key escapes the storage root: {key}")
        return path

    async def put(self, key: str, data: bytes) -> None:
        path = self._path(key)
        path.parent.mkdir(parents=True, exist_ok=True)
        await asyncio.to_thread(path.write_bytes, data)

    async def get(self, key: str) -> bytes:
        path = self._path(key)
        if not path.is_file():
            raise FileNotFoundError(key)
        return await asyncio.to_thread(path.read_bytes)

    async def delete(self, key: str) -> None:
        self._path(key).unlink(missing_ok=True)


class S3Storage:
    """Any S3-compatible store. The client is boto3's, made by from_settings."""

    def __init__(self, client, bucket: str):
        self.client = client
        self.bucket = bucket

    @classmethod
    def from_settings(cls):
        import boto3  # only an install that chose S3 needs it

        client = boto3.client("s3", endpoint_url=settings.S3_ENDPOINT or None,
                              aws_access_key_id=settings.S3_KEY or None,
                              aws_secret_access_key=settings.S3_SECRET or None,
                              region_name=settings.S3_REGION or None)
        return cls(client, settings.S3_BUCKET)

    async def put(self, key: str, data: bytes) -> None:
        await asyncio.to_thread(self.client.put_object, Bucket=self.bucket, Key=key, Body=data)

    async def get(self, key: str) -> bytes:
        def read():
            try:
                return self.client.get_object(Bucket=self.bucket, Key=key)["Body"].read()
            except Exception as error:
                raise FileNotFoundError(key) from error
        return await asyncio.to_thread(read)

    async def delete(self, key: str) -> None:
        await asyncio.to_thread(self.client.delete_object, Bucket=self.bucket, Key=key)


_current = None


def current():
    """The install's storage, made once."""
    global _current
    if _current is None:
        _current = (S3Storage.from_settings() if settings.FILE_STORAGE == "s3"
                    else LocalStorage(settings.FILE_STORAGE_ROOT))
    return _current
