"""Validated, versioned data contract for the dispatcher module only."""
from enum import StrEnum
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)


class Category(StrEnum):
    NETWORK = "network"
    TELEMETRY = "telemetry"
    PRINTING = "printing"
    ACCESS = "access"
    APPLICATIONS = "applications"
    SECURITY = "security"
    OTHER = "other"


class Priority(StrEnum):
    LOW = "low"
    NORMAL = "normal"
    HIGH = "high"
    EMERGENCY = "emergency"


class IntakeRequest(StrictModel):
    text: str = Field(min_length=1, max_length=12000)
    source: Literal["text", "voice", "phone"] = "text"


class ExtractedFields(StrictModel):
    object: str | None = Field(default=None, max_length=300)
    site: str | None = Field(default=None, max_length=300)
    building: str | None = Field(default=None, max_length=100)
    floor: str | None = Field(default=None, max_length=100)
    room: str | None = Field(default=None, max_length=100)
    ip: str | None = Field(default=None, max_length=64)
    equipment: str | None = Field(default=None, max_length=300)
    meter: str | None = Field(default=None, max_length=200)
    serial: str | None = Field(default=None, max_length=200)
    incident_time: str | None = Field(default=None, max_length=200)
    error_code: str | None = Field(default=None, max_length=200)
    symptoms: str | None = Field(default=None, max_length=2000)
    scope: str | None = Field(default=None, max_length=1000)


class ModelAnalysis(StrictModel):
    category: Category
    priority: Priority
    priority_reason: str = Field(min_length=1, max_length=1500)
    fields: ExtractedFields = Field(default_factory=ExtractedFields)
    missing_fields: list[str] = Field(default_factory=list, max_length=20)
    questions: list[str] = Field(default_factory=list, max_length=5)
    confidence_notes: list[str] = Field(default_factory=list, max_length=20)
    tags: list[str] = Field(default_factory=list, max_length=10)

    @field_validator("missing_fields", "questions", "confidence_notes", "tags")
    @classmethod
    def bound_items(cls, items: list[str]) -> list[str]:
        if any(not item.strip() or len(item) > 1000 for item in items):
            raise ValueError("List items must contain 1..1000 characters")
        return items


class Engineer(StrictModel):
    id: str = Field(min_length=1, max_length=128)
    team_id: str = Field(min_length=1, max_length=128)
    skills: list[Category] = Field(min_length=1, max_length=7)
    open_tickets: int = Field(default=0, ge=0, strict=True)
    available: bool = Field(default=True, strict=True)


class IntakeResult(StrictModel):
    contract_version: Literal["1.0"] = "1.0"
    status: Literal["needs_clarification", "awaiting_review", "failed"]
    # Correlation ID; persistence and final ticket ID belong to the integrator.
    draft_id: str
    raw_text: str
    source: Literal["text", "voice", "phone"]
    category: Category | None = None
    priority: Priority | None = None
    priority_reason: str = ""
    fields: ExtractedFields = Field(default_factory=ExtractedFields)
    missing_fields: list[str] = Field(default_factory=list)
    questions: list[str] = Field(default_factory=list)
    confidence_notes: list[str] = Field(default_factory=list)
    tags: list[str] = Field(default_factory=list)
    recommended_team_id: str | None = None
    recommended_engineer_id: str | None = None
    recommendation_reason: str = ""
    warnings: list[str] = Field(default_factory=list)
    requires_dispatcher_review: Literal[True] = True
    error_code: str | None = None


class SlaAssessment(StrictModel):
    state: Literal["normal", "warning", "overdue"]
    elapsed_seconds: float
    remaining_seconds: float
    reason: str
