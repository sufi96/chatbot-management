import os
from contextlib import asynccontextmanager
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from fastapi.responses import FileResponse, Response

from config import settings
import database
from database import init_db
from routers import bot, chat, kb, voice

@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup: initialize database tables and seed if needed
    print("[Engine] Initializing database...")
    await init_db()
    print("[Engine] FastAPI Streaming Engine is ready.")
    yield
    # Shutdown
    print("[Engine] Shutting down...")

app = FastAPI(
    title="Chatbot Management - Streaming Engine API",
    version="1.1.0",
    lifespan=lifespan
)

# Enable CORS for widget embedding across client domains
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Register API Routers
app.include_router(bot.router)
app.include_router(chat.router)
app.include_router(kb.router)
app.include_router(voice.router)

@app.get("/health")
async def health_check():
    degraded = database.active_backend == "sqlite-fallback"
    return {
        "status": "degraded" if degraded else "ok",
        "service": "fastapi-llm-engine",
        "version": "1.1.0",
        "database": database.active_backend,
    }

# Serve widget.js directly at root level
widget_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "widget"))
widget_path = os.path.join(widget_dir, "widget.js")
markdown_path = os.path.join(widget_dir, "markdown.js")
voice_path = os.path.join(widget_dir, "voice.js")

SCRIPT_HEADERS = {"Cache-Control": "no-cache, must-revalidate"}


@app.get("/widget.js")
async def get_widget_script():
    """The widget, its Markdown renderer and its voice helpers, as one script.

    They are separate files so the helpers can be unit tested on their own,
    and joined here so embedding stays a single tag and a single request.
    """
    parts = [markdown_path, voice_path, widget_path]
    if not all(os.path.exists(path) for path in parts):
        return {"error": "widget.js not found"}

    sources = []
    for path in parts:
        with open(path, encoding="utf-8") as f:
            sources.append(f.read())

    return Response(
        content="\n;\n".join(sources),
        media_type="application/javascript",
        headers=SCRIPT_HEADERS,
    )


@app.get("/widget-voice.js")
async def get_voice_script():
    """The voice helpers alone, so the portal's voice preview picks a device
    voice exactly as the widget will."""
    if os.path.exists(voice_path):
        return FileResponse(voice_path, media_type="application/javascript", headers=SCRIPT_HEADERS)
    return {"error": "voice.js not found"}


@app.get("/widget-markdown.js")
async def get_markdown_script():
    """The renderer alone, so the admin portal can format transcripts too."""
    if os.path.exists(markdown_path):
        return FileResponse(
            markdown_path,
            media_type="application/javascript",
            headers=SCRIPT_HEADERS,
        )
    return {"error": "markdown.js not found"}

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "main:app", 
        host=settings.API_HOST, 
        port=settings.API_PORT, 
        reload=True
    )
