import os
from contextlib import asynccontextmanager
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from fastapi.responses import FileResponse

from config import settings
from database import init_db
from routers import bot, chat

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
    version="1.0.0",
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

@app.get("/health")
async def health_check():
    return {"status": "ok", "service": "fastapi-llm-engine", "version": "1.0.0"}

# Serve widget.js directly at root level
widget_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "widget", "widget.js"))

@app.get("/widget.js")
async def get_widget_script():
    if os.path.exists(widget_path):
        return FileResponse(
            widget_path, 
            media_type="application/javascript",
            headers={"Cache-Control": "no-cache, must-revalidate"}
        )
    return {"error": "widget.js not found"}

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(
        "main:app", 
        host=settings.API_HOST, 
        port=settings.API_PORT, 
        reload=True
    )
