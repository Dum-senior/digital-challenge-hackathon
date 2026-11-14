import json
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from models import IntakeRequest, IntakeResult
root=Path(__file__).resolve().parents[1]/'docs'
for name,model in [('request',IntakeRequest),('response',IntakeResult)]:
    (root/(name+'.schema.json')).write_text(json.dumps(model.model_json_schema(),ensure_ascii=False,indent=2),encoding='utf-8')
print('Exported request/response schemas.')
