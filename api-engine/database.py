import uuid
from datetime import datetime
from typing import AsyncGenerator
from sqlalchemy import Column, String, Text, Integer, Float, DateTime, ForeignKey, select
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
    bot_avatar_url = Column(String(500), nullable=True)
    avatar_shape = Column(String(30), default="circle")
    is_active = Column(Integer, default=1)
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


# Engine and Session initialization
engine = None
async_session_factory = None

async def init_db():
    global engine, async_session_factory
    
    # Try primary PostgreSQL connection
    try:
        test_engine = create_async_engine(settings.DATABASE_URL, echo=False)
        async with test_engine.begin() as conn:
            # Test query
            await conn.run_sync(lambda _: None)
        engine = test_engine
        print(f"[DB] Successfully connected to PostgreSQL: {settings.DATABASE_URL.split('@')[-1]}")
    except Exception as e:
        if settings.USE_SQLITE_FALLBACK:
            print(f"[DB] PostgreSQL unavailable ({e}). Falling back to local SQLite: {settings.SQLITE_FALLBACK_URL}")
            engine = create_async_engine(settings.SQLITE_FALLBACK_URL, echo=False)
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
                is_active=1
            )
            session.add(default_bot)
            await session.commit()
            print("[DB] Initialized default system and demo bot profile.")

async def get_db() -> AsyncGenerator[AsyncSession, None]:
    async with async_session_factory() as session:
        yield session
