import os
from pydantic_settings import BaseSettings

class Settings(BaseSettings):
    # Default to PostgreSQL, with fallback to SQLite for local development
    DATABASE_URL: str = os.getenv(
        "DATABASE_URL", 
        "postgresql+asyncpg://postgres:postgres@127.0.0.1:5432/chatbot_hub"
    )
    SQLITE_FALLBACK_URL: str = f"sqlite+aiosqlite:///{os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'admin-laravel', 'database', 'database.sqlite')).replace(os.sep, '/')}"
    # Set USE_SQLITE_FALLBACK=false once PostgreSQL holds the real data, so an
    # unreachable database fails loudly instead of quietly serving a stale file.
    # Laravel stores uploads on its public disk; the engine reads them from
    # disk rather than over HTTP, so it needs the same directory.
    UPLOAD_ROOT: str = os.path.abspath(os.path.join(
        os.path.dirname(__file__), "..", "admin-laravel", "storage", "app", "public"))

    USE_SQLITE_FALLBACK: bool = os.getenv("USE_SQLITE_FALLBACK", "true").lower() not in ("0", "false", "no")
    
    # Shared secret for the admin-plane routes. Read through Settings so the
    # engine's .env file is honoured; os.getenv alone would not see it.
    ADMIN_API_TOKEN: str = os.getenv("ADMIN_API_TOKEN", "")

    API_PORT: int = int(os.getenv("PORT", "8000"))
    API_HOST: str = os.getenv("HOST", "0.0.0.0")
    
    CORS_ORIGINS: list[str] = ["*"]
    
    class Config:
        env_file = ".env"
        extra = "allow"

settings = Settings()
