import uuid
from datetime import datetime
from typing import AsyncGenerator
from sqlalchemy import Column, String, Text, Integer, Float, DateTime, ForeignKey, select, Boolean
from sqlalchemy.ext.asyncio import create_async_engine, AsyncSession, async_sessionmaker
from sqlalchemy.orm import declarative_base, relationship
from config import settings

Base = declarative_base()

class System(Base):
    __tablename__ = "systems"

    id = Column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    name = Column(String(255), nullable=False)
    description = Column(Text, nullable=True)
    allowed_origins = Column(Text, default="*")
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)

    bots = relationship("BotProfile", back_populates="system", cascade="all, delete-orphan")


class BotProfile(Base):
    __tablename__ = "bot_profiles"

    id = Column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    system_id = Column(String(36), ForeignKey("systems.id", ondelete="CASCADE"), nullable=False)
    name = Column(String(255), nullable=False)
    system_prompt = Column(Text, default="You are a helpful, courteous, and accurate AI assistant.")
    provider_type = Column(String(50), default="ollama")  # 'ollama' or 'custom'
    base_url = Column(String(500), default="http://localhost:11434/v1")
    api_key = Column(String(500), default="")
    model_name = Column(String(255), default="llama3.2")
    temperature = Column(Float, default=0.7)
    max_tokens = Column(Integer, default=1024)
    widget_title = Column(String(255), default="AI Assistant")
    widget_greeting = Column(Text, default="Hello! How can I help you today?")
    widget_primary_color = Column(String(20), default="#4F46E5")
    widget_position = Column(String(20), default="bottom-right")  # 'bottom-right' or 'bottom-left'
    launcher_icon_url = Column(String(500), nullable=True)
    launcher_shape = Column(String(30), default="circle")
    launcher_size = Column(Integer, default=60)
    close_icon_url = Column(String(500), nullable=True)
    close_shape = Column(String(30), default="circle")
    close_size = Column(Integer, default=52)
    bot_avatar_url = Column(String(500), nullable=True)
    avatar_shape = Column(String(30), default="circle")

    # Retrieval settings, edited on the bot's Brain page.
    retrieval_enabled = Column(Boolean, default=False)
    retrieval_mode = Column(String(20), default="hybrid")
    retrieval_top_k = Column(Integer, default=5)
    retrieval_candidates = Column(Integer, default=30)
    retrieval_min_score = Column(Float, default=0.0)
    retrieval_fallback = Column(String(20), default="say_unknown")

    # Generation settings, passed through to the model endpoint.
    top_p = Column(Float, default=1.0)
    top_k_sampling = Column(Integer, nullable=True)
    presence_penalty = Column(Float, default=0.0)
    frequency_penalty = Column(Float, default=0.0)
    thinking_level = Column(String(10), default="off")

    is_active = Column(Boolean, default=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)

    system = relationship("System", back_populates="bots")
    conversations = relationship("ChatConversation", back_populates="bot", cascade="all, delete-orphan")


class ChatConversation(Base):
    __tablename__ = "chat_conversations"

    id = Column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    bot_id = Column(String(36), ForeignKey("bot_profiles.id", ondelete="CASCADE"), nullable=False)
    session_id = Column(String(100), nullable=False)
    origin = Column(String(500), default="")
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)

    bot = relationship("BotProfile", back_populates="conversations")
    messages = relationship("ChatMessage", back_populates="conversation", cascade="all, delete-orphan")


class ChatMessage(Base):
    __tablename__ = "chat_messages"

    id = Column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    conversation_id = Column(String(36), ForeignKey("chat_conversations.id", ondelete="CASCADE"), nullable=False)
    sender = Column(String(20), nullable=False)  # 'user', 'assistant', 'system'
    content = Column(Text, nullable=False)
    tokens_used = Column(Integer, default=0)
    created_at = Column(DateTime, default=datetime.utcnow)

    conversation = relationship("ChatConversation", back_populates="messages")


class KbCollection(Base):
    __tablename__ = "kb_collections"

    id = Column(String(36), primary_key=True)
    system_id = Column(String(36), ForeignKey("systems.id", ondelete="CASCADE"), nullable=False)
    name = Column(String(255), nullable=False)
    description = Column(Text, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


class KbSource(Base):
    __tablename__ = "kb_sources"

    id = Column(String(36), primary_key=True)
    collection_id = Column(String(36), ForeignKey("kb_collections.id", ondelete="CASCADE"), nullable=False)
    type = Column(String(20), default="text")
    title = Column(String(500), nullable=False)
    body = Column(Text, nullable=True)
    file_path = Column(String(500), nullable=True)
    file_mime = Column(String(100), nullable=True)
    file_size = Column(Integer, nullable=True)
    status = Column(String(20), default="pending")
    error_message = Column(Text, nullable=True)
    chunk_count = Column(Integer, default=0)
    indexed_at = Column(DateTime, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


class KbChunk(Base):
    """The embedding column is deliberately not mapped.

    It is vector(768) on PostgreSQL and a blob on SQLite, so each vector store
    driver reads and writes it with raw SQL instead.
    """
    __tablename__ = "kb_chunks"

    id = Column(Integer, primary_key=True, autoincrement=True)
    collection_id = Column(String(36), nullable=False, index=True)
    source_id = Column(String(36), ForeignKey("kb_sources.id", ondelete="CASCADE"), nullable=False)
    ordinal = Column(Integer, nullable=False)
    content = Column(Text, nullable=False)
    char_count = Column(Integer, default=0)
    heading_path = Column(String(500), nullable=True)
    embedding_model = Column(String(120), nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)


class BotKbCollection(Base):
    __tablename__ = "bot_kb_collection"

    id = Column(Integer, primary_key=True, autoincrement=True)
    bot_id = Column(String(36), ForeignKey("bot_profiles.id", ondelete="CASCADE"), nullable=False)
    collection_id = Column(String(36), ForeignKey("kb_collections.id", ondelete="CASCADE"), nullable=False)


class AppSetting(Base):
    __tablename__ = "app_settings"

    key = Column(String(120), primary_key=True)
    value = Column(Text, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


# Kept in step with AppSetting::DEFAULTS on the Laravel side.
SETTING_DEFAULTS = {
    "embedding_base_url": "http://localhost:11434/v1",
    "embedding_api_key": "",
    "embedding_model": "nomic-embed-text",
    "embedding_dimensions": "768",
    "vector_driver": "pgvector",
    "chunk_size": "1800",
    "chunk_overlap": "200",
    "context_char_budget": "6000",
}


async def get_settings(session) -> dict:
    """Stored settings layered over the defaults, read fresh each time.

    Laravel writes these; reading them from the shared table is what stops the
    two halves of the system drifting apart.
    """
    result = await session.execute(select(AppSetting))
    stored = {row.key: row.value for row in result.scalars().all() if row.value is not None}
    return {**SETTING_DEFAULTS, **stored}


# Engine and Session initialization
engine = None
async_session_factory = None

# Which backend init_db actually reached. Reported by /health so a silent
# fallback cannot masquerade as a healthy service.
active_backend = "uninitialised"

async def init_db():
    global engine, async_session_factory, active_backend
    
    # Try primary PostgreSQL connection
    try:
        test_engine = create_async_engine(settings.DATABASE_URL, echo=False)
        async with test_engine.begin() as conn:
            # Test query
            await conn.run_sync(lambda _: None)
        engine = test_engine
        active_backend = "postgresql"
        print(f"[DB] Successfully connected to PostgreSQL: {settings.DATABASE_URL.split('@')[-1]}")
    except Exception as e:
        if settings.USE_SQLITE_FALLBACK:
            # Falling back is right on a machine that never had PostgreSQL. It is
            # dangerous once PostgreSQL holds the real data: the SQLite file is a
            # frozen copy, so the widget would serve stale bots and new
            # conversations would be written somewhere the admin cannot see.
            # Hence the banner rather than one quiet line.
            engine = create_async_engine(settings.SQLITE_FALLBACK_URL, echo=False)
            active_backend = "sqlite-fallback"
            print("")
            print("=" * 72)
            print("  WARNING: PostgreSQL is unreachable. Running on the SQLite fallback.")
            print(f"  Reason: {e}")
            print(f"  File:   {settings.SQLITE_FALLBACK_URL}")
            print("")
            print("  If PostgreSQL is your real database, STOP the engine now. That")
            print("  file is a stale copy: bot changes will not appear and new")
            print("  conversations will be written where the admin cannot read them.")
            print("  Set USE_SQLITE_FALLBACK=false to make this a hard failure.")
            print("=" * 72)
            print("")
        else:
            raise e

    async_session_factory = async_sessionmaker(engine, expire_on_commit=False, class_=AsyncSession)

    # Auto-create tables if they don't exist
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)

    # Seed default data if empty
    async with async_session_factory() as session:
        result = await session.execute(select(System).limit(1))
        existing_sys = result.scalars().first()
        if not existing_sys:
            default_sys = System(
                id="sys_default_01",
                name="Default Workspace",
                description="Primary workspace for web applications",
                allowed_origins="*"
            )
            session.add(default_sys)
            await session.flush()

            default_bot = BotProfile(
                id="bot_demo_default",
                system_id=default_sys.id,
                name="Customer Support Bot",
                system_prompt="You are a polite, helpful and concise AI assistant for customer service.",
                provider_type="ollama",
                base_url="http://localhost:11434/v1",
                api_key="",
                model_name="llama3.2",
                widget_title="Support Assistant",
                widget_greeting="Hi there! 👋 How can I help you today?",
                widget_primary_color="#4F46E5",
                widget_position="bottom-right",
                is_active=True
            )
            session.add(default_bot)
            await session.commit()
            print("[DB] Initialized default system and demo bot profile.")

async def get_db() -> AsyncGenerator[AsyncSession, None]:
    async with async_session_factory() as session:
        yield session
