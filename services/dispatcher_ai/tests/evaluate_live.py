"""Opt-in evaluation against configured real LLM; never run as part of pytest.

Run: uv run python tests/evaluate_live.py --output evaluation.local.json
Requires the server-side LLM environment documented by the service.
Only synthetic fixture text is sent; reports do not contain credentials.
"""
import argparse
import asyncio
import json
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from dispatcher_core import DispatcherService
from models import IntakeRequest
from dotenv import load_dotenv
load_dotenv(ROOT / ".env", override=False)

async def evaluate():
    data=json.loads((ROOT/'fixtures/intake_cases.ru.json').read_text(encoding='utf-8'))
    service=DispatcherService()
    rows=[]
    for case in data['cases']:
        start=time.monotonic()
        result=await service.analyze(IntakeRequest(text=case['text'],source=case['source']))
        expected=case['expected']
        actual=result.model_dump(mode='json')
        rows.append({'case_id':case['id'],'processing_ms':round((time.monotonic()-start)*1000), 'actual':actual, 'checks':{'category_matches_label':result.category==expected['category'],'priority_matches_label':result.priority==expected['priority'],'no_unstated_fields':all(getattr(result.fields,k) is None for k in expected['must_remain_null'])}})
    return {'synthetic_inputs_only':True,'description':'Actual responses from configured LLM. Expected labels are a draft demonstration policy; inspect mismatches manually. Failed calls count as failures, not model successes.', 'case_count':len(rows),'completed_without_failure':sum(r['actual']['status']!='failed' for r in rows),'results':rows}

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args()
    report=asyncio.run(evaluate())
    args.output.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(f'Cases: {report["case_count"]}; completed: {report["completed_without_failure"]}; report: {args.output}')
