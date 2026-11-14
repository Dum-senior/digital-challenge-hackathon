"""Strict wire contracts. Catalog data is supplied by a trusted PHP adapter only."""
from datetime import datetime
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

Category = Literal["peripherals", "telemetry", "automation", "network", "security", "access", "database", "unknown"]
Priority = Literal["low", "medium", "high", "critical"]
Status = Literal["awaiting_review", "needs_clarification", "failed"]
Source = Literal["text", "voice", "phone"]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)


class Team(StrictModel):
    id: int = Field(gt=0)
    name: str = Field(min_length=1, max_length=100)
    categories: list[Category] = Field(default_factory=list, max_length=10)


class Engineer(StrictModel):
    id: int = Field(gt=0)
    team_ids: list[int] = Field(min_length=1, max_length=32)
    skills: list[str] = Field(default_factory=list, max_length=40)
    open_tickets: int = Field(ge=0, le=100000)
    available: bool = True

    @field_validator("team_ids")
    @classmethod
    def check_team_ids(cls, ids: list[int]) -> list[int]:
        if any(x <= 0 for x in ids) or len(set(ids)) != len(ids):
            raise ValueError("invalid or duplicate team id")
        return ids

    @field_validator("skills")
    @classmethod
    def canonical_skills(cls, values: list[str]) -> list[str]:
        return [v.strip().lower()[:80] for v in values]


class TrustedContext(StrictModel):
    # Caller MUST be the authenticated server-side OpenHelpDesk integration.
    teams: list[Team] = Field(default_factory=list, max_length=200)
    engineers: list[Engineer] = Field(default_factory=list, max_length=2000)
    sla_due_at: datetime | None = None

    @model_validator(mode="after")
    def check_catalog(self) -> "TrustedContext":
        team_ids = [team.id for team in self.teams]
        engineer_ids = [engineer.id for engineer in self.engineers]
        if len(team_ids) != len(set(team_ids)) or len(engineer_ids) != len(set(engineer_ids)):
            raise ValueError("duplicate team/engineer ids")
        known = set(team_ids)
        if any(not set(engineer.team_ids).issubset(known) for engineer in self.engineers):
            raise ValueError("engineer references unknown team")
        return self

    @field_validator("sla_due_at")
    @classmethod
    def due_timezone(cls, value: datetime | None) -> datetime | None:
        if value is not None and value.utcoffset() is None:
            raise ValueError("SLA deadline requires timezone")
        return value


class IntakeRequest(StrictModel):
    text: str = Field(min_length=3, max_length=4000)
    source: Source
    context: TrustedContext | None = None

    @field_validator("text")
    @classmethod
    def text_control_chars(cls, s: str) -> str:
        if any(ord(c) < 32 and c not in "\n\t\r" for c in s):
            raise ValueError("invalid control chars")
        return s


class Fields(StrictModel):
    object: str | None = None
    site: str | None = None
    building: str | None = None
    floor: str | None = None
    room: str | None = None
    ip: str | None = None
    equipment: str | None = None
    meter_number: str | None = None
    serial_number: str | None = None
    incident_time: str | None = None
    error_code: str | None = None
    symptoms: str | None = None
    scope: str | None = None


class ModelSuggestion(StrictModel):
    # Untrusted model output; only whitelisted, evidence-backed fields are used.
    category: Category
    priority: Priority
    priority_reason: str = Field(min_length=1, max_length=300)
    fields: Fields
    missing_fields: list[str] = Field(default_factory=list, max_length=12)
    confidence_notes: list[str] = Field(default_factory=list, max_length=12)


class IntakeResponse(StrictModel):
    status: Status
    analysis_id: str
    draft_id: None = None  # PHP/OpenHelpDesk fills this only after PERSISTING a draft.
    persisted: Literal[False] = False
    source: Source
    analysis_mode: Literal["rules_only", "private_llm", "rules_fallback"]
    category: Category
    priority: Priority
    priority_reason: str
    fields: Fields
    missing_fields: list[str]
    recommended_team_id: int | None
    recommended_engineer_id: int | None
    assignment_reason: str
    sla_risk: Literal["unknown", "on_track", "warning", "breached"]
    safety_warnings: list[str]
    confidence_notes: list[str]
    requires_human_review: Literal[True] = True
