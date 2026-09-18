from typing import Any

from fastapi.testclient import TestClient

from app.config import Settings
from app.exceptions import FaceServiceError
from app.main import create_app


TOKEN = "test-token-with-at-least-32-characters"


class FakeEngine:
    model_loaded = True

    def __init__(self, result: dict[str, Any] | None = None) -> None:
        self.result = result or {
            "verified": True,
            "similarity": 98.25,
            "distance": 0.12,
            "threshold": 0.3,
            "minimum_similarity": 70.0,
            "provider": "deepface-facenet512",
            "model": "Facenet512",
            "detector_backend": "opencv",
            "anti_spoofing_enabled": True,
        }

    def warm_up(self) -> None:
        return None

    def verify(self, _: bytes, __: bytes) -> dict[str, Any]:
        return self.result


class SpoofEngine(FakeEngine):
    def verify(self, _: bytes, __: bytes) -> dict[str, Any]:
        raise FaceServiceError("spoof_detected", "Spoof terdeteksi.")


def settings() -> Settings:
    return Settings(token=TOKEN, warmup=False)


def files() -> dict[str, tuple[str, bytes, str]]:
    return {
        "profile_photo": ("profile.jpg", b"profile-image", "image/jpeg"),
        "clock_in_photo": ("clock-in.jpg", b"clock-in-image", "image/jpeg"),
    }


def test_verify_requires_a_valid_token() -> None:
    with TestClient(create_app(settings(), FakeEngine())) as client:
        response = client.post("/api/v1/verify", files=files())

    assert response.status_code == 401
    assert response.json()["error"]["code"] == "unauthorized"


def test_verify_returns_the_engine_result() -> None:
    with TestClient(create_app(settings(), FakeEngine())) as client:
        response = client.post(
            "/api/v1/verify",
            headers={"Authorization": f"Bearer {TOKEN}"},
            files=files(),
        )

    assert response.status_code == 200
    assert response.json()["verified"] is True
    assert response.json()["similarity"] == 98.25
    assert response.json()["minimum_similarity"] == 70.0


def test_verify_returns_a_stable_spoof_error() -> None:
    with TestClient(create_app(settings(), SpoofEngine())) as client:
        response = client.post(
            "/api/v1/verify",
            headers={"Authorization": f"Bearer {TOKEN}"},
            files=files(),
        )

    assert response.status_code == 422
    assert response.json() == {
        "error": {"code": "spoof_detected", "message": "Spoof terdeteksi."}
    }


def test_verify_rejects_unsupported_file_types() -> None:
    invalid_files = files()
    invalid_files["clock_in_photo"] = ("clock-in.gif", b"gif", "image/gif")

    with TestClient(create_app(settings(), FakeEngine())) as client:
        response = client.post(
            "/api/v1/verify",
            headers={"Authorization": f"Bearer {TOKEN}"},
            files=invalid_files,
        )

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "face_not_found"
