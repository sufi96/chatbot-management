"""Skill admin routes for the portal, guarded by the shared admin token."""
from fastapi import APIRouter, Depends
from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from database import AiProvider, get_db
from llm_adapter import LLMAdapter
from routers.kb import require_admin_token

router = APIRouter(prefix="/api/v1/skills", tags=["skills"])

INSTRUCTION = (
    "Write one SKILL.md file for a customer-support chatbot, and nothing else: no code fence, "
    "no commentary. Start with front matter between two '---' lines holding exactly these keys: "
    "name (lowercase letters, digits and hyphens, at most 64), description (one sentence starting "
    "'Use when' that says when the skill applies), and tools (a list in square brackets of the "
    "operation names the steps call, or [] when none). After the front matter, write short numbered "
    "steps: what to ask the visitor first, which tool to call with what, how to explain the result, "
    "and what to do when something is missing. Never tell the bot to invent data."
)


class DraftRequest(BaseModel):
    provider_id: str
    model: str
    description: str = Field(max_length=2000)
    # The workspace's switched-on operations. Without them the model invents
    # names, and a skill needing a tool nobody has is offered to no bot.
    operations: list[str] = Field(default_factory=list, max_length=200)


def instruction_for(operations: list[str]) -> str:
    names = [name.strip() for name in operations if name and name.strip()][:200]
    if not names:
        return INSTRUCTION + " This workspace has no operations yet, so write tools: [] and call none."
    return (INSTRUCTION + " The only operations that exist are: " + ", ".join(names)
            + ". Name only these, exactly as written, and only the ones the steps really call.")


@router.post("/draft", dependencies=[Depends(require_admin_token)])
async def draft_skill(req: DraftRequest, db: AsyncSession = Depends(get_db)) -> dict:
    provider = (await db.execute(select(AiProvider).where(AiProvider.id == req.provider_id))).scalars().first()
    if provider is None:
        return {"success": False, "message": "That provider no longer exists."}

    text = await LLMAdapter.complete(
        base_url=provider.base_url, api_key=provider.api_key or "", model_name=req.model,
        system_prompt=instruction_for(req.operations), user_message=f"The skill: {req.description}",
        temperature=0.2, max_tokens=1500, merge_system=bool(provider.merge_system_prompt))

    if not text:
        return {"success": False, "message": "The model wrote nothing. Check the provider and model, or try again."}

    return {"success": True, "skill": text.strip()}
