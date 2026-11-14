"""Optional internal HTTP facade. No helpdesk database, sessions or role changes."""
import json
import os
import secrets
from pathlib import Path
from contextlib import asynccontextmanager
from dotenv import load_dotenv
from fastapi import FastAPI, Header, HTTPException, Depends
from pydantic import TypeAdapter
from models import IntakeRequest, IntakeResult, Engineer
from dispatcher_core import DispatcherService

ROOT=Path(__file__).resolve().parent

@asynccontextmanager
async def lifespan(app):
    load_dotenv(ROOT / '.env', override=False)
    if len(os.getenv('DISPATCHER_API_TOKEN','')) < 32:
        raise RuntimeError('Set DISPATCHER_API_TOKEN with at least 32 characters; run tools/init_secrets.py')
    yield

app=FastAPI(title='Den · Dispatcher AI internal service',version='0.1.0',lifespan=lifespan)

def authorize(authorization: str | None = Header(default=None)):
    expected=os.getenv('DISPATCHER_API_TOKEN','')
    if not expected or not authorization or not secrets.compare_digest(authorization, 'Bearer '+expected):
        raise HTTPException(401,'Invalid service credentials')

def engineers():
    configured=os.getenv('ENGINEERS_FILE','')
    if not configured:return []
    path=Path(configured)
    if not path.is_absolute():path=ROOT/path
    try:
        return TypeAdapter(list[Engineer]).validate_json(path.read_text(encoding='utf-8-sig'))
    except (OSError,ValueError):
        raise HTTPException(503,'Engineer catalog unavailable; repair server configuration')

@app.get('/health')
def health():return {'status':'ok','service':'dispatcher-ai','inference_verified':False}

@app.post('/api/hackathon/v1/intake/analyze', response_model=IntakeResult, dependencies=[Depends(authorize)])
async def analyze(request: IntakeRequest):
    return await DispatcherService(engineers=engineers()).analyze(request)
