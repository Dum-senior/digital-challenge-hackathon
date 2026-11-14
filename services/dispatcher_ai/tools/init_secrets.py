"""Provision two independent local secrets; never print their values."""
import os
import secrets
from pathlib import Path

path = Path(__file__).resolve().parents[1] / '.env'
content = ('DISPATCHER_API_TOKEN=' + secrets.token_urlsafe(48) + '\n'
           'LLM_BASE_URL=http://127.0.0.1:8001/v1\n'
           'LLM_MODEL=Qwen/Qwen2.5-7B-Instruct\n'
           'LLM_API_KEY=' + secrets.token_urlsafe(48) + '\n'
           'ENGINEERS_FILE=\n')
try:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
except FileExistsError:
    raise SystemExit('Existing .env preserved; rotate secrets explicitly if needed.')
with os.fdopen(fd, 'w', encoding='utf-8') as f:
    f.write(content)
print('Created local .env. Values hidden. Configure the same LLM_API_KEY on your inference server.')
