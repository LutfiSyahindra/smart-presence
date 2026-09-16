import os
from pathlib import Path

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


SERVICE_ROOT = Path(__file__).resolve().parent.parent
PROJECT_ROOT = SERVICE_ROOT.parent.parent

# Keep downloaded DeepFace models beside the service by default. This avoids
# filling the OS drive during local development while preserving an explicit
# DEEPFACE_HOME supplied by Docker or the deployment environment.
os.environ.setdefault("DEEPFACE_HOME", str(SERVICE_ROOT))


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=(PROJECT_ROOT / ".env", SERVICE_ROOT / ".env"),
        env_file_encoding="utf-8",
        env_prefix="FACE_SERVICE_",
        extra="ignore",
    )

    token: str = ""
    model_name: str = "Facenet512"
    detector_backend: str = "opencv"
    distance_metric: str = "cosine"
    distance_threshold: float | None = Field(default=None, ge=0)
    anti_spoofing: bool = True
    max_upload_bytes: int = Field(default=5 * 1024 * 1024, ge=1024, le=20 * 1024 * 1024)
    min_image_dimension: int = Field(default=160, ge=64, le=2048)
    max_image_dimension: int = Field(default=6000, ge=512, le=12000)
    inference_max_dimension: int = Field(default=1280, ge=320, le=4096)
    profile_embedding_cache_size: int = Field(default=512, ge=0, le=10000)
    warmup: bool = True

    def validate_runtime(self) -> None:
        if len(self.token) < 32:
            raise RuntimeError("FACE_SERVICE_TOKEN wajib diisi minimal 32 karakter.")

        if self.min_image_dimension > self.max_image_dimension:
            raise RuntimeError(
                "FACE_SERVICE_MIN_IMAGE_DIMENSION tidak boleh melebihi "
                "FACE_SERVICE_MAX_IMAGE_DIMENSION."
            )

        if self.min_image_dimension > self.inference_max_dimension:
            raise RuntimeError(
                "FACE_SERVICE_MIN_IMAGE_DIMENSION tidak boleh melebihi "
                "FACE_SERVICE_INFERENCE_MAX_DIMENSION."
            )
