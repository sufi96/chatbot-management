import asyncio
from database import init_db
import main

async def test():
    print("Testing init_db...")
    await init_db()
    print("DB initialized successfully!")

if __name__ == "__main__":
    asyncio.run(test())
