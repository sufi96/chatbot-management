import os
from pydantic_settings import BaseSettings

class Settings(BaseSettings):
    # Default to PostgreSQL, with fallback to SQLite for local development
    DATABASE_URL: str = os.getenv(
        "DATABASE_URL", 
        "postgresql+asyncpg://postgres:postgres@127.0.0.1:5432/chatbot_hub"
    )
    SQLITE_FALLBACK_URL: str = f"sqlite+aiosqlite:///{os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'admin-laravel', 'database', 'database.sqlite')).replace(os.sep, '/')}"
    USE_SQLITE_FALLBACK: bool = True
    
    API_PORT: int = int(os.getenv("PORT", "8000"))
    API_HOST: str = os.getenv("HOST", "0.0.0.0")
    
    CORS_ORIGINS: list[str] = ["*"]
    
    class Config:
        env_file = ".env"
        extra = "allow"

settings = Settings()
