"""Deterministic safety guards, source-grounded extraction and offline fallback."""
import ipaddress
import re
from datetime import datetime, timezone

from .schema import Fields, TrustedContext


CATEGORY_SKILL = {
    "peripherals": "printer", "telemetry": "telemetry", "automation": "opc",
    "network": "network", "security": "security", "access": "identity",
    "database": "database",
}


def match(pattern: str, text: str, flags: int = re.I) -> str | None:
    found = re.search(pattern, text, flags)
    return found.group(0) if found else None


def extract_fields(text: str) -> Fields:
    raw = text.strip()
    ip = None
    for candidate in re.findall(r"(?<!\d)(?:\d{1,3}\.){3}\d{1,3}(?!\d)", raw):
        try:
            ip = str(ipaddress.IPv4Address(candidate))
            break
        except ipaddress.AddressValueError:
            pass
    tm = re.search(r"\b(?:[01]?\d|2[0-3]):[0-5]\d\b", raw)
    morning = re.search(r"\bс\s+(\d{1,2})\s+утра\b", raw, re.I)
    incident_time = tm.group(0).zfill(5) if tm else (f"{int(morning.group(1)):02d}:00" if morning and int(morning.group(1)) < 12 else None)
    equipment = match(r"\b(?:принтер\w*|сч[её]тчик\w*|сервер\w*|компьютер\w*|шлюз\w*|OPC(?:-сервер\w*)?|VPN|маршрутизатор\w*)\b", raw)
    code = match(r"(?<![\w.])(?:0x[0-9A-Fa-f]{4,8}|08\d{3}|ORA-\d{4,6})(?!\w)", raw)
    obj = match(r"\b(?:НТП|КП[-–]?\d{1,5})\b", raw)
    building = match(r"\bкорпус(?:е|а|у)?\s+[А-ЯA-Z0-9-]{1,5}\b", raw)
    room = match(r"\bкабинет(?:е|а)?\s*\d{1,5}\b", raw)
    floor = match(r"\bэтаж(?:е|а)?\s*\d{1,2}\b", raw)
    serial = match(r"\b(?:S/?N|серийн(?:ый|ого)\s+номер)\s*[:№#-]?\s*[A-Z0-9-]{4,25}\b", raw)
    meter = match(r"\b(?:сч[её]тчик(?:а|у)?\s*(?:№|номер)\s*)\d{2,20}\b", raw)
    scope = match(r"\b(?:во\s+вс[её]м\s+корпусе\s+[А-ЯA-Z]|нескольк(?:их|о)\s+отдел(?:ов|а)|у\s+всех\s+пользователей|массов(?:ый|ое|ая)\s+отказ\w*)\b", raw)
    return Fields(object=obj, site=None, building=building, floor=floor, room=room, ip=ip,
                  equipment=equipment, meter_number=meter, serial_number=serial,
                  incident_time=incident_time, error_code=code, symptoms=raw[:1500], scope=scope)


def classify(text: str) -> tuple[str, str, str, list[str], list[str]]:
    t = text.casefold()
    danger = any(x in t for x in ("дым", "горит", "пожар", "искрит", "задым", "запах гари", "выкуп за файл", "вымогател", "ransomware"))
    security = any(x in t for x in ("выкуп за файл", "вымогател", "ransomware", "вредонос", "взлом", "шифровальщ"))
    vague = (len(t.split()) <= 5 and ("ничего не работает" in t or "всё сломалось" in t or "все сломалось" in t))
    if security:
        cat = "security"
    elif any(x in t for x in ("opc", "асу тп", "плк", "scada")):
        cat = "automation"
    elif any(x in t for x in ("счётчик", "счетчик", "телеметр", "показани")):
        cat = "telemetry"
    elif any(x in t for x in ("бд", "баз", "sql", "08001")):
        cat = "database"
    elif any(x in t for x in ("vpn", "сеть", "связ", "dns", "интернет")):
        cat = "network"
    elif any(x in t for x in ("учётка", "учетка", "пароль", "заблокирован", "войти", "доступ")):
        cat = "access"
    elif any(x in t for x in ("принтер", "печата", "картридж")):
        cat = "peripherals"
    else:
        cat = "unknown"
    mass = any(x in t for x in ("во всём корпус", "во всем корпус", "несколько отдел", "нескольких отдел", "массов", "у всех", "всем подразделен"))
    warnings = []
    if danger:
        priority = "critical"
        reason = "Обнаружены признаки возможной угрозы безопасности; требуется немедленная проверка человеком"
        warnings.append("Возможная авария/инцидент ИБ: не перезапускать и не изменять оборудование по совету ИИ; немедленно уведомить ответственных по утверждённому регламенту.")
    elif mass:
        priority = "high"
        reason = "Сообщено о затрагивании нескольких пользователей или подразделений"
    elif cat in ("automation", "telemetry"):
        priority = "high"
        reason = "Прерван поток промышленных данных; демонстрационное правило, не норматив предприятия"
    elif cat == "database":
        priority = "high"
        reason = "Недоступен сервис данных; масштаб и влияние требует подтверждения"
    elif cat == "peripherals":
        priority = "low"
        reason = "Локальная неисправность периферии без явных аварийных признаков"
    else:
        priority = "medium"
        reason = "Требуется стандартная проверка диспетчером; оценка по демонстрационным правилам"
    missing = []
    if vague or cat == "unknown":
        missing = ["объект или место", "какая система не работает", "масштаб неисправности", "симптомы или код ошибки"]
    elif cat == "telemetry" and ("счётчик" in t or "счетчик" in t) and not match(r"сч[её]тчик(?:а|у)?\s*(?:№|номер)\s*\d+", text):
        missing = ["номер счётчика"]
    elif cat in ("peripherals", "access") and not match(r"кабинет(?:е|а)?\s*\d+", text):
        missing = ["расположение пользователя/устройства"]
    return cat, priority, reason, missing, warnings


def suggest_assignment(category: str, ctx: TrustedContext | None) -> tuple[int | None, int | None, str]:
    if ctx is None or not ctx.teams:
        return None, None, "Каталог инженеров из OpenHelpDesk не предоставлен; назначение не предлагается"
    teams = sorted((x for x in ctx.teams if category in x.categories), key=lambda x: x.id)
    if not teams:
        return None, None, "Нет подтверждённой подходящей команды в каталоге OpenHelpDesk"
    skill = CATEGORY_SKILL.get(category)
    for team in teams:
        eligible = [e for e in ctx.engineers if e.available and team.id in e.team_ids and skill in e.skills]
        if eligible:
            choice = min(eligible, key=lambda e: (e.open_tickets, e.id))
            return team.id, choice.id, f"Компетенция {skill}, доступен, открытых заявок: {choice.open_tickets}; итог проверяет диспетчер"
    return teams[0].id, None, "Подходящая команда найдена, инженер с подтверждённым навыком и доступностью не найден"


def sla_risk(ctx: TrustedContext | None) -> str:
    if ctx is None or ctx.sla_due_at is None:
        return "unknown"
    seconds = (ctx.sla_due_at - datetime.now(timezone.utc)).total_seconds()
    return "breached" if seconds <= 0 else ("warning" if seconds <= 1800 else "on_track")
