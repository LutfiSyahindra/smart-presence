import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.concurrency import run_in_threadpool

from app.api.routes import router
from app.config import Settings
from app.exceptions import FaceServiceError
from app.services.deepface_engine import DeepFaceEngine


logger = logging.getLogger(__name__)


def create_app(
    settings: Settings | None = None,
    engine: DeepFaceEngine | None = None,
) -> FastAPI:
    resolved_settings = settings or Settings()
    resolved_engine = engine or DeepFaceEngine(resolved_settings)

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        resolved_settings.validate_runtime()
        if resolved_settings.warmup:
            await run_in_threadpool(resolved_engine.warm_up)
        yield

    application = FastAPI(
        title="RSARSY Face Verification",
        version="1.0.0",
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
        lifespan=lifespan,
    )
    application.state.settings = resolved_settings
    application.state.engine = resolved_engine
    application.include_router(router, prefix="/api/v1")

    @application.exception_handler(FaceServiceError)
    async def face_service_error_handler(
        _: Request,
        exception: FaceServiceError,
    ) -> JSONResponse:
        return JSONResponse(
            status_code=exception.status_code,
            content={
                "error": {
                    "code": exception.code,
                    "message": exception.message,
                }
            },
        )

    @application.exception_handler(RequestValidationError)
    async def validation_error_handler(
        _: Request,
        __: RequestValidationError,
    ) -> JSONResponse:
        return JSONResponse(
            status_code=422,
            content={
                "error": {
                    "code": "invalid_request",
                    "message": "Permintaan verifikasi wajah tidak valid.",
                }
            },
        )

    @application.exception_handler(Exception)
    async def unexpected_error_handler(_: Request, exception: Exception) -> JSONResponse:
        logger.error(
            "Kesalahan tak terduga pada face verification service.",
            exc_info=(type(exception), exception, exception.__traceback__),
        )
        return JSONResponse(
            status_code=500,
            content={
                "error": {
                    "code": "service_unavailable",
                    "message": "Layanan verifikasi wajah sedang tidak tersedia.",
                }
            },
        )

    return application


app = create_app()
