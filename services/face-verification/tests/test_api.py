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
            "minimum_similarity": 51.0,
            "provider": "deepface-facenet512",
            "model": "Facenet512",
            "detector_backend": "yunet",
            "anti_spoofing_enabled": True,
        }

    def warm_up(self) -> None:
        return None

    def verify(self, _: bytes, __: bytes) -> dict[str, Any]:
        return self.result

    def validate_profile(self, contents: bytes) -> dict[str, bool]:
        assert contents == b"profile-image"
        return {"valid": True}


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
    assert response.json()["minimum_similarity"] == 51.0


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


def test_profile_validation_requires_a_valid_token() -> None:
    with TestClient(create_app(settings(), FakeEngine())) as client:
        response = client.post("/api/v1/profile/validate", files={"profile_photo": files()["profile_photo"]})

    assert response.status_code == 401


def test_profile_validation_accepts_a_validated_reference() -> None:
    with TestClient(create_app(settings(), FakeEngine())) as client:
        response = client.post(
            "/api/v1/profile/validate", headers={"Authorization": f"Bearer {TOKEN}"},
            files={"profile_photo": files()["profile_photo"]},
        )

    assert response.status_code == 200
    assert response.json() == {"valid": True}


def test_profile_validation_returns_a_quality_error() -> None:
    class InvalidProfileEngine(FakeEngine):
        def validate_profile(self, _: bytes) -> dict[str, bool]:
            raise FaceServiceError("invalid_profile_photo", "Foto wajah terlalu buram.")

    with TestClient(create_app(settings(), InvalidProfileEngine())) as client:
        response = client.post(
            "/api/v1/profile/validate", headers={"Authorization": f"Bearer {TOKEN}"},
            files={"profile_photo": files()["profile_photo"]},
        )

    assert response.status_code == 422
    assert response.json()["error"]["code"] == "invalid_profile_photo"


def test_profile_validation_rejects_unsupported_empty_and_oversized_files() -> None:
    for upload in [("x.gif", b"gif", "image/gif"), ("x.jpg", b"", "image/jpeg"), ("x.jpg", b"x" * (5 * 1024 * 1024 + 1), "image/jpeg")]:
        with TestClient(create_app(settings(), FakeEngine())) as client:
            response = client.post(
                "/api/v1/profile/validate", headers={"Authorization": f"Bearer {TOKEN}"},
                files={"profile_photo": upload},
            )

        assert response.status_code == 422
        assert response.json()["error"]["code"] == "invalid_profile_photo"
