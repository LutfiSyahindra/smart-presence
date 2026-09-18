from pydantic import BaseModel, Field


class VerificationResponse(BaseModel):
    verified: bool
    similarity: float | None = Field(default=None, ge=0, le=100)
    distance: float | None = Field(default=None, ge=0)
    threshold: float | None = Field(default=None, ge=0)
    minimum_similarity: float = Field(ge=0, le=100)
    provider: str = Field(min_length=1, max_length=50)
    model: str
    detector_backend: str
    anti_spoofing_enabled: bool


class HealthResponse(BaseModel):
    status: str
    model_loaded: bool | None = None
