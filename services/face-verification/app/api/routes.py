import secrets
from typing import Annotated, Any

from fastapi import APIRouter, File, Header, Request, UploadFile
from starlette.concurrency import run_in_threadpool

from app.config import Settings
from app.exceptions import FaceServiceError
from app.schemas import HealthResponse, ProfileValidationResponse, VerificationResponse
from app.services.deepface_engine import DeepFaceEngine


router = APIRouter()
ALLOWED_IMAGE_TYPES = {"image/jpeg", "image/png", "image/webp"}


@router.get("/health/live", response_model=HealthResponse)
async def liveness() -> HealthResponse:
    return HealthResponse(status="ok")


@router.get("/health/ready", response_model=HealthResponse)
async def readiness(request: Request) -> HealthResponse:
    engine: DeepFaceEngine = request.app.state.engine
    return HealthResponse(status="ready", model_loaded=engine.model_loaded)


@router.post("/profile/validate", response_model=ProfileValidationResponse)
async def validate_profile_photo(
    request: Request,
    profile_photo: Annotated[UploadFile, File(description="Foto profil referensi")],
    authorization: Annotated[str | None, Header()] = None,
) -> ProfileValidationResponse:
    settings: Settings = request.app.state.settings
    _authenticate(authorization, settings)
    contents = await _read_image(profile_photo, settings, profile_photo=True)
    engine: DeepFaceEngine = request.app.state.engine
    result = await run_in_threadpool(engine.validate_profile, contents)

    return ProfileValidationResponse.model_validate(result)


@router.post("/verify", response_model=VerificationResponse)
async def verify_faces(
    request: Request,
    profile_photo: Annotated[UploadFile, File(description="Foto profil referensi")],
    clock_in_photo: Annotated[UploadFile, File(description="Foto clock-in dari kamera")],
    authorization: Annotated[str | None, Header()] = None,
) -> VerificationResponse:
    settings: Settings = request.app.state.settings
    _authenticate(authorization, settings)

    profile_bytes = await _read_image(profile_photo, settings, profile_photo=True)
    clock_in_bytes = await _read_image(clock_in_photo, settings, profile_photo=False)
    engine: DeepFaceEngine = request.app.state.engine
    result: dict[str, Any] = await run_in_threadpool(
        engine.verify,
        profile_bytes,
        clock_in_bytes,
    )

    return VerificationResponse.model_validate(result)


def _authenticate(authorization: str | None, settings: Settings) -> None:
    expected = f"Bearer {settings.token}"
    if authorization is None or not secrets.compare_digest(authorization, expected):
        raise FaceServiceError(
            "unauthorized",
            "Token service tidak valid.",
            status_code=401,
        )


async def _read_image(
    upload: UploadFile,
    settings: Settings,
    *,
    profile_photo: bool,
) -> bytes:
    code = "invalid_profile_photo" if profile_photo else "face_not_found"
    label = "profil" if profile_photo else "clock-in"

    if upload.content_type not in ALLOWED_IMAGE_TYPES:
        raise FaceServiceError(
            code,
            f"Format foto {label} harus JPG, PNG, atau WebP.",
        )

    contents = await upload.read(settings.max_upload_bytes + 1)
    if not contents:
        raise FaceServiceError(code, f"File foto {label} kosong.")

    if len(contents) > settings.max_upload_bytes:
        max_megabytes = settings.max_upload_bytes / (1024 * 1024)
        raise FaceServiceError(
            code,
            f"Ukuran foto {label} maksimal {max_megabytes:g} MB.",
        )

    return contents
