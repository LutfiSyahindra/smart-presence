from io import BytesIO
from typing import Any

import numpy as np
import pytest
from deepface.modules.exceptions import FaceNotDetected, SpoofDetected
from PIL import Image

from app.config import Settings
from app.exceptions import FaceServiceError
from app.services.deepface_engine import DeepFaceEngine


TOKEN = "test-token-with-at-least-32-characters"


class FakeDeepFace:
    def __init__(self, *, verified: bool = True, confidence: float | None = 91.5) -> None:
        self.extract_calls: list[dict[str, Any]] = []
        self.represent_calls: list[dict[str, Any]] = []
        self.verify_calls: list[dict[str, Any]] = []
        self.detections: list[list[dict[str, Any]] | Exception] = []
        self.verified = verified
        self.confidence = confidence

    def extract_faces(self, **kwargs: Any) -> list[dict[str, Any]]:
        self.extract_calls.append(kwargs)
        if self.detections:
            detection = self.detections.pop(0)
            if isinstance(detection, Exception):
                raise detection
            return detection
        return [
            {
                "face": np.zeros((160, 160, 3), dtype=np.float32),
                "is_real": True,
            }
        ]

    def represent(self, **kwargs: Any) -> Any:
        self.represent_calls.append(kwargs)
        if isinstance(kwargs["img_path"], list):
            return [
                [{"embedding": [1.0, 0.0]}],
                [{"embedding": [0.9, 0.1]}],
            ]

        return [{"embedding": [0.9, 0.1]}]

    def verify(self, **kwargs: Any) -> dict[str, Any]:
        self.verify_calls.append(kwargs)
        return {
            "verified": self.verified,
            "confidence": self.confidence,
            "distance": 0.1,
            "threshold": 0.3,
        }


def image_bytes(width: int, height: int, color: str = "white") -> bytes:
    buffer = BytesIO()
    Image.new("RGB", (width, height), color=color).save(buffer, format="JPEG")
    return buffer.getvalue()


def test_profile_embedding_is_cached_and_faces_are_not_detected_twice() -> None:
    fake = FakeDeepFace()
    engine = DeepFaceEngine(
        Settings(
            token=TOKEN,
            warmup=False,
            inference_max_dimension=1280,
            profile_embedding_cache_size=2,
        )
    )
    engine._deepface = fake
    profile = image_bytes(2400, 1200)
    clock_in = image_bytes(800, 600)

    first = engine.verify(profile, clock_in)
    second = engine.verify(profile, clock_in)

    assert first["verified"] is True
    assert second["verified"] is True
    assert len(fake.extract_calls) == 3
    assert [call["anti_spoofing"] for call in fake.extract_calls] == [True, False, True]
    assert max(fake.extract_calls[1]["img_path"].shape) == 1280
    assert isinstance(fake.represent_calls[0]["img_path"], list)
    assert len(fake.represent_calls[0]["img_path"]) == 2
    assert isinstance(fake.represent_calls[1]["img_path"], np.ndarray)
    assert all(call["detector_backend"] == "skip" for call in fake.verify_calls)
    assert all(isinstance(call["img1_path"], list) for call in fake.verify_calls)


def test_model_match_below_minimum_similarity_is_rejected() -> None:
    fake = FakeDeepFace(confidence=39.99)
    engine = DeepFaceEngine(
        Settings(token=TOKEN, warmup=False, min_similarity=40.0)
    )
    engine._deepface = fake

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is False
    assert result["similarity"] == 39.99
    assert result["minimum_similarity"] == 40.0


def test_model_match_at_minimum_similarity_is_accepted() -> None:
    fake = FakeDeepFace(verified=False, confidence=40.0)
    engine = DeepFaceEngine(
        Settings(token=TOKEN, warmup=False, min_similarity=40.0)
    )
    engine._deepface = fake

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is True


@pytest.mark.parametrize("confidence", [None, float("nan"), float("inf")])
def test_model_match_without_valid_similarity_is_rejected(confidence: float | None) -> None:
    fake = FakeDeepFace(confidence=confidence)
    engine = DeepFaceEngine(
        Settings(token=TOKEN, warmup=False, min_similarity=40.0)
    )
    engine._deepface = fake

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is False
    assert result["similarity"] is None


def test_similarity_above_new_minimum_overrides_default_model_distance_decision() -> None:
    fake = FakeDeepFace(verified=False, confidence=63.0)
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False, min_similarity=40.0))
    engine._deepface = fake

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is True
    assert result["similarity"] == 63.0


def test_explicit_distance_limit_is_still_enforced() -> None:
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False, distance_threshold=0.05))
    engine._deepface = FakeDeepFace()

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is False
    assert result["similarity"] == 91.5


def test_explicit_higher_similarity_limit_is_still_enforced() -> None:
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False, min_similarity=70.0))
    engine._deepface = FakeDeepFace(confidence=63.0)

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is False


@pytest.mark.parametrize("missing", [[], FaceNotDetected("Face could not be detected")])
def test_missing_detection_uses_fallback_with_alignment_and_anti_spoofing(missing: Any) -> None:
    fake = FakeDeepFace()
    fake.detections = [missing]
    engine = DeepFaceEngine(Settings(
        token=TOKEN, warmup=False,
        detector_backend="yunet", detector_fallback_backend="opencv",
    ))
    engine._deepface = fake

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is True
    assert [call["detector_backend"] for call in fake.extract_calls] == ["yunet", "opencv", "yunet"]
    assert [call["anti_spoofing"] for call in fake.extract_calls] == [True, True, False]
    assert all(call["enforce_detection"] and call["align"] for call in fake.extract_calls)


@pytest.mark.parametrize("profile_photo,code", [(False, "face_not_found"), (True, "invalid_profile_photo")])
def test_missing_face_after_both_detectors_is_rejected(profile_photo: bool, code: str) -> None:
    fake = FakeDeepFace()
    fake.detections = [FaceNotDetected("No face"), []]
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False))

    with pytest.raises(FaceServiceError) as caught:
        engine._extract_single_face(fake, np.zeros((600, 800, 3), dtype=np.uint8), profile_photo=profile_photo)

    assert caught.value.code == code
    assert len(fake.extract_calls) == 2
    assert not fake.represent_calls


@pytest.mark.parametrize("detection,code,status", [
    ([{"is_real": True}, {"is_real": True}], "multiple_faces", 422),
    ([{"is_real": False}], "spoof_detected", 422),
    ([{}], "spoof_detected", 422),
    (SpoofDetected("Spoof detected"), "spoof_detected", 422),
    (ValueError("Detector model is invalid"), "service_unavailable", 503),
])
def test_detection_failures_never_retry_to_bypass_a_rejection(detection: Any, code: str, status: int) -> None:
    fake = FakeDeepFace()
    fake.detections = [detection]
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False))
    engine._deepface = fake

    with pytest.raises(FaceServiceError) as caught:
        engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert caught.value.code == code
    assert caught.value.status_code == status
    assert len(fake.extract_calls) == 1
    assert not fake.represent_calls
    assert not fake.verify_calls


def test_same_detector_is_not_retried_as_its_own_fallback() -> None:
    fake = FakeDeepFace()
    fake.detections = [[]]
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False, detector_backend="opencv"))

    with pytest.raises(FaceServiceError):
        engine._extract_single_face(fake, np.zeros((600, 800, 3), dtype=np.uint8), profile_photo=False)

    assert len(fake.extract_calls) == 1


@pytest.mark.parametrize("options", [{"detector_backend": "skip"}, {"detector_fallback_backend": "skip"}])
def test_detectors_cannot_skip_face_detection(options: dict[str, str]) -> None:
    with pytest.raises(RuntimeError, match="Deteksi wajah wajib"):
        Settings(token=TOKEN, **options).validate_runtime()


def test_profile_cache_is_invalidated_when_photo_contents_change() -> None:
    fake = FakeDeepFace()
    engine = DeepFaceEngine(
        Settings(token=TOKEN, warmup=False, profile_embedding_cache_size=2)
    )
    engine._deepface = fake
    clock_in = image_bytes(800, 600)

    engine.verify(image_bytes(800, 600, "white"), clock_in)
    engine.verify(image_bytes(800, 600, "black"), clock_in)

    assert len(fake.extract_calls) == 4
    assert len(fake.represent_calls) == 2
    assert all(isinstance(call["img_path"], list) for call in fake.represent_calls)
