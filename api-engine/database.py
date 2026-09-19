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


class AiProvider(Base):
    """An OpenAI-compatible endpoint, saved once and shared by many bots.

    Bots link to one rather than copying it, so an operator who changes
    machines or rotates a key edits a single row.
    """
    __tablename__ = "ai_providers"

    id = Column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    # Null for a platform provider: one Admin Settings links a model job to,
    # owned by no workspace and never offered to a bot.
    system_id = Column(String(36), ForeignKey("systems.id", ondelete="CASCADE"), nullable=True)
    name = Column(String(255), nullable=False)
    base_url = Column(String(500), nullable=False)
    api_key = Column(String(500), default="")
    # Set for a gateway that silently drops system messages. The engine then
    # sends instructions inside the user message. See llm_adapter.with_instructions.
    merge_system_prompt = Column(Boolean, default=False)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)

    bots = relationship("BotProfile", back_populates="provider")


class BotProfile(Base):
    __tablename__ = "bot_profiles"

    id = Column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    # Null only for the console assistant, which belongs to the platform.
    system_id = Column(String(36), ForeignKey("systems.id", ondelete="CASCADE"), nullable=True)
    name = Column(String(255), nullable=False)
    system_prompt = Column(Text, default="You are a helpful, courteous, and accurate AI assistant.")
    provider_id = Column(String(36), ForeignKey("ai_providers.id", ondelete="SET NULL"), nullable=True)
    model_name = Column(String(255), default="llama3.2")
    temperature = Column(Float, default=0.7)
    max_tokens = Column(Integer, default=1024)
    widget_title = Column(String(255), default="AI Assistant")
    widget_greeting = Column(Text, default="Hello! How can I help you today?")
    widget_primary_color = Column(String(20), default="#4F46E5")
    widget_header_color = Column(String(20), nullable=True)
    widget_header_text_color = Column(String(20), default="#FFFFFF")
    widget_header_image_url = Column(String(500), nullable=True)
    widget_header_image_opacity = Column(Integer, default=100)
    widget_background_color = Column(String(20), default="#FAFAFA")
    widget_background_image_url = Column(String(500), nullable=True)
    widget_background_image_opacity = Column(Integer, default=100)
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
    # Read instead of retrieval_min_score when a reranker is configured.
    rerank_min_score = Column(Float, default=0.1)
    # Without a reranker, the knowledge base only counts as having an answer
    # when some passage is at least this similar to the question.
    retrieval_min_similarity = Column(Float, default=0.65)
    retrieval_fallback = Column(String(20), default="say_unknown")

    # The web is consulted only when the knowledge base above found nothing.
    web_search_enabled = Column(Boolean, default=False)
    web_search_max_results = Column(Integer, default=3)
    web_search_country = Column(String(2), nullable=True)

    # The database branch. A router picks between it, the documents and the
    # web; these only say whether it is available and how much it may return.
    db_query_enabled = Column(Boolean, default=False)
    db_max_rows = Column(Integer, default=50)
    db_query_timeout = Column(Integer, default=10)
    # Which source answers first. Read through sources.order.normalise, never
    # raw, because a hand written value must not make a source unreachable.
    source_order = Column(String(64), default="documents,database,web")
    # Whether each question is read with the conversation before it. See intent.py.
    intent_enabled = Column(Boolean, default=False)
    # Whether what comes in and what goes out is checked. See guard.py.
    guard_enabled = Column(Boolean, default=False)
    guard_refusal = Column(Text, nullable=True)
    # Topics this bot also refuses, on top of the platform's. One per line.
    guard_topics = Column(Text, nullable=True)

    # Generation settings, passed through to the model endpoint.
    top_p = Column(Float, default=1.0)
    top_k_sampling = Column(Integer, nullable=True)
    presence_penalty = Column(Float, default=0.0)
    frequency_penalty = Column(Float, default=0.0)
    thinking_level = Column(String(10), default="off")

    is_active = Column(Boolean, default=True)
    # The console's own assistant. Laravel creates it; it answers only on the
    # console's own pages. See portal_origin_allowed.
    is_platform = Column(Boolean, default=False)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)
    # Set when the bot is deleted from its workspace. Laravel owns it; a bot
    # with one is gone as far as the engine is concerned.
    deleted_at = Column(DateTime, nullable=True)

    system = relationship("System", back_populates="bots")
    provider = relationship("AiProvider", back_populates="bots", lazy="selectin")
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
    # What a reasoning model narrated before answering, kept out of content.
    reasoning = Column(Text, nullable=True)
    # The statement that produced this answer, and how many rows it found.
    # An operator auditing a wrong answer needs the query, not a guess at it.
    db_sql = Column(Text, nullable=True)
    db_row_count = Column(Integer, nullable=True)
    # JSON naming the model behind each job, written by roles.model_trace.
    model_trace = Column(Text, nullable=True)
    # On the visitor's row: what the intent step decided, and what the sources
    # searched for when that differed from the words sent.
    intent = Column(String(20), nullable=True)
    intent_query = Column(Text, nullable=True)
    # What the guard named: on the visitor's row when a message was refused,
    # on the assistant's row when an answer was flagged.
    guard_flag = Column(String(60), nullable=True)
    # On the assistant's row, for the analytics page: which source answered
    # (see sources.answer_kind), the citations the widget was sent as JSON, and
    # how long the visitor waited for the first token and for the whole reply.
    source_kind = Column(String(20), nullable=True)
    citations = Column(Text, nullable=True)
    first_token_ms = Column(Integer, nullable=True)
    response_ms = Column(Integer, nullable=True)
    tokens_used = Column(Integer, default=0)
    # The two halves of tokens_used, prompt and completion. Null when the
    # endpoint reported no usage, and on answers saved before they were kept.
    tokens_in = Column(Integer, nullable=True)
    tokens_out = Column(Integer, nullable=True)
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
    description = Column(Text, nullable=True)
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


class DbConnection(Base):
    """A workspace's own database. Laravel owns this table; we only read it."""
    __tablename__ = "db_connections"

    id = Column(String(36), primary_key=True)
    # Null only for the console's own database, which the console assistant reads.
    system_id = Column(String(36), ForeignKey("systems.id", ondelete="CASCADE"), nullable=True)
    name = Column(String(255), nullable=False)
    driver = Column(String(20), nullable=False)
    # Credentials are deliberately not modelled here. The engine never opens
    # this connection; Laravel does, and Laravel holds the password.
    database = Column(String(255), nullable=False)
    # The master switch. A ticked table inside a switched-off connection is
    # not readable, which is the whole point of it.
    is_enabled = Column(Boolean, default=True)


class DbTable(Base):
    __tablename__ = "db_tables"

    id = Column(Integer, primary_key=True, autoincrement=True)
    connection_id = Column(String(36), ForeignKey("db_connections.id", ondelete="CASCADE"), nullable=False)
    schema_name = Column(String(128), nullable=True)
    table_name = Column(String(128), nullable=False)
    # Written by a person who understands the business. This is what the
    # model reads instead of the table itself.
    description = Column(Text, nullable=True)
    is_enabled = Column(Boolean, default=False)
    is_present = Column(Boolean, default=True)


class DbColumn(Base):
    __tablename__ = "db_columns"

    id = Column(Integer, primary_key=True, autoincrement=True)
    table_id = Column(Integer, ForeignKey("db_tables.id", ondelete="CASCADE"), nullable=False)
    column_name = Column(String(128), nullable=False)
    data_type = Column(String(64), nullable=True)
    is_nullable = Column(Boolean, default=True)
    is_primary_key = Column(Boolean, default=False)
    # How this column joins to another table. Discovered where the database
    # declares a foreign key, written by hand where it does not.
    foreign_key_target = Column(String(255), nullable=True)
    description = Column(Text, nullable=True)
    ordinal = Column(Integer, default=0)
    is_present = Column(Boolean, default=True)


class BotDbConnection(Base):
    __tablename__ = "bot_db_connection"

    id = Column(Integer, primary_key=True, autoincrement=True)
    bot_id = Column(String(36), ForeignKey("bot_profiles.id", ondelete="CASCADE"), nullable=False)
    connection_id = Column(String(36), ForeignKey("db_connections.id", ondelete="CASCADE"), nullable=False)


class AppSetting(Base):
    __tablename__ = "app_settings"

    key = Column(String(120), primary_key=True)
    value = Column(Text, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


# Kept in step with AppSetting::DEFAULTS on the Laravel side.
SETTING_DEFAULTS = {
    # Admin Settings stores the provider link; get_settings expands it into the
    # URL and key below, which is all the embedding client reads. Blank keeps
    # the local default.
    "embedding_provider_id": "",
    "embedding_base_url": "http://localhost:11434/v1",
    "embedding_api_key": "",
    "embedding_model": "nomic-embed-text",
    "embedding_dimensions": "768",
    "chunk_size": "1800",
    "chunk_overlap": "200",
    "context_char_budget": "6000",
    "web_search_provider": "duckduckgo",
    "web_search_tavily_key": "",
    "web_search_brave_key": "",
    # Blank means each bot uses its own endpoint and model. Small models are
    # markedly weaker at SQL than at conversation, so an install can point
    # query work somewhere stronger without making every chat cost more.
    "sql_model_provider_id": "",
    "sql_model_base_url": "",
    "sql_model_api_key": "",
    "sql_model_name": "",
    # The same shape for every other job a model does. roles.py decides what
    # blank falls back to; see there.
    "intent_model_provider_id": "",
    "intent_model_base_url": "",
    "intent_model_api_key": "",
    "intent_model_name": "",
    "rerank_model_provider_id": "",
    "rerank_model_base_url": "",
    "rerank_model_api_key": "",
    "rerank_model_name": "",
    # What the guard blocks. guard.py holds the category keys; every one is on
    # by default, which is what the guard did before it had settings.
    "guard_categories": "violence,illegal,sexual,self_harm,hate,personal_data,jailbreak,political,copyright",
    "guard_topics": "",
    "guard_borderline": "allow",
    "guard_model_provider_id": "",
    "guard_model_base_url": "",
    "guard_model_api_key": "",
    "guard_model_name": "",
    "vision_model_provider_id": "",
    "vision_model_base_url": "",
    "vision_model_api_key": "",
    "vision_model_name": "",
}


async def get_settings(session) -> dict:
    """Stored settings layered over the defaults, read fresh each time.

    Laravel writes these; reading them from the shared table is what stops the
    two halves of the system drifting apart.
    """
    result = await session.execute(select(AppSetting))
    stored = {row.key: row.value for row in result.scalars().all() if row.value is not None}
    return await _expand_provider_links(session, {**SETTING_DEFAULTS, **stored})


PROVIDER_LINK_SUFFIX = "provider_id"


async def _expand_provider_links(session, settings: dict) -> dict:
    """Turn each `<job>_provider_id` into the `<job>_base_url` and
    `<job>_api_key` it stands for.

    Admin Settings links a job to a platform provider, so an endpoint edited
    once moves every job on it. Callers keep reading URL and key, which is why
    roles.py and the embedding client did not have to learn about providers.

    A link to a provider that no longer exists expands to nothing. Falling back
    to a default would hide the break behind a model that answers differently.
    """
    links = {key[: -len(PROVIDER_LINK_SUFFIX)]: value
             for key, value in settings.items()
             if key.endswith(PROVIDER_LINK_SUFFIX) and value}

    if not links:
        return settings

    result = await session.execute(
        select(AiProvider).where(AiProvider.id.in_(set(links.values()))))
    providers = {row.id: row for row in result.scalars().all()}

    for prefix, provider_id in links.items():
        provider = providers.get(provider_id)
        settings[prefix + "base_url"] = provider.base_url if provider else ""
        settings[prefix + "api_key"] = (provider.api_key or "") if provider else ""
        settings[prefix + "merge_system"] = bool(provider and getattr(provider, "merge_system_prompt", False))

    return settings


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

            default_provider = AiProvider(
                id="aip_default_local",
                system_id=default_sys.id,
                name="Local Ollama",
                base_url="http://localhost:11434/v1",
                api_key="",
            )
            session.add(default_provider)
            await session.flush()

            default_bot = BotProfile(
                id="bot_demo_default",
                system_id=default_sys.id,
                name="Customer Support Bot",
                system_prompt="You are a polite, helpful and concise AI assistant for customer service.",
                provider_id=default_provider.id,
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

def provider_endpoint(bot) -> tuple[str, str]:
    """Where this bot's model lives, read through the provider it points at.

    A bot with no provider is a misconfiguration rather than a crash: callers
    report an endpoint they could not reach, instead of losing the request to
    an AttributeError on None.
    """
    provider = bot.provider

    if provider is None:
        return "", ""

    return provider.base_url, provider.api_key or ""


def provider_merges_system(bot) -> bool:
    """Whether this bot's provider drops system messages. See AiProvider."""
    provider = bot.provider if bot is not None else None
    return bool(provider and getattr(provider, "merge_system_prompt", False))


async def get_db() -> AsyncGenerator[AsyncSession, None]:
    async with async_session_factory() as session:
        yield session
