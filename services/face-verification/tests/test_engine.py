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
    fake = FakeDeepFace(verified=True, confidence=40.0)
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


@pytest.mark.parametrize("confidence", [40.0, 49.0, 63.0, 99.0])
def test_similarity_never_overrides_a_model_rejection(confidence: float) -> None:
    fake = FakeDeepFace(verified=False, confidence=confidence)
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False, min_similarity=40.0))
    engine._deepface = fake

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is False
    assert result["similarity"] == confidence
    assert result["minimum_similarity"] == 40.0
    assert fake.verify_calls[0]["threshold"] is None


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


def profile_image(*, face_size: int = 240, brightness: int = 128, sharp: bool = True) -> tuple[bytes, dict[str, Any]]:
    pixels = np.full((600, 800, 3), 128, dtype=np.uint8)
    noise = np.random.default_rng(42).integers(-30, 31, (face_size, face_size, 3)) if sharp else 0
    pixels[100:100 + face_size, 100:100 + face_size] = np.clip(brightness + noise, 0, 255)
    output = BytesIO()
    Image.fromarray(pixels).save(output, format="PNG")
    return output.getvalue(), {
        "face": np.zeros((160, 160, 3), dtype=np.float32),
        "facial_area": {"x": 100, "y": 100, "w": face_size, "h": face_size},
    }


def test_valid_profile_is_analyzed_and_cached_for_the_next_attendance() -> None:
    contents, face = profile_image()
    fake = FakeDeepFace()
    fake.detections = [[face]]
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False))
    engine._deepface = fake

    assert engine.validate_profile(contents) == {"valid": True}
    assert fake.extract_calls[0]["anti_spoofing"] is False
    assert engine.verify(contents, image_bytes(800, 600))["verified"] is True
    assert len(fake.extract_calls) == 2
    assert len(fake.represent_calls) == 2
    assert all(not isinstance(call["img_path"], list) for call in fake.represent_calls)


@pytest.mark.parametrize("options,message", [
    ({"face_size": 80}, "terlalu kecil"),
    ({"brightness": 10}, "Pencahayaan"),
    ({"brightness": 245}, "Pencahayaan"),
    ({"sharp": False}, "terlalu buram"),
])
def test_profile_quality_uses_the_face_region_and_rejects_poor_references(options: dict[str, Any], message: str) -> None:
    contents, face = profile_image(**options)
    fake = FakeDeepFace()
    fake.detections = [[face]]
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False))
    engine._deepface = fake

    with pytest.raises(FaceServiceError, match=message) as caught:
        engine.validate_profile(contents)

    assert caught.value.code == "invalid_profile_photo"
    assert not fake.represent_calls
    assert not engine._profile_embeddings


@pytest.mark.parametrize("faces", [[], [None, None]])
def test_profile_upload_requires_exactly_one_detected_face(faces: list[Any]) -> None:
    contents, face = profile_image()
    fake = FakeDeepFace()
    fake.detections = [[face for _ in faces], []]
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False))
    engine._deepface = fake

    with pytest.raises(FaceServiceError) as caught:
        engine.validate_profile(contents)

    assert caught.value.code == "invalid_profile_photo"
    assert not fake.represent_calls


def test_profile_upload_rejects_low_original_resolution_before_detection() -> None:
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False))
    fake = FakeDeepFace()
    engine._deepface = fake

    with pytest.raises(FaceServiceError, match="480"):
        engine.validate_profile(image_bytes(240, 240))

    assert not fake.extract_calls


def test_a_looser_explicit_distance_limit_cannot_relax_the_model_decision() -> None:
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False, distance_threshold=1.0))
    fake = FakeDeepFace(verified=False, confidence=99.0)
    engine._deepface = fake

    assert engine.verify(image_bytes(800, 600), image_bytes(800, 600))["verified"] is False
    assert fake.verify_calls[0]["threshold"] is None


def test_a_model_match_between_40_and_the_previous_minimum_is_accepted() -> None:
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False, min_similarity=40.0))
    engine._deepface = FakeDeepFace(verified=True, confidence=49.0)

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is True
    assert result["minimum_similarity"] == 40.0


@pytest.mark.parametrize("embedding", [[], [float("nan")], [float("inf")], None])
def test_profile_validation_rejects_an_unusable_embedding(embedding: Any) -> None:
    class InvalidEmbeddingDeepFace(FakeDeepFace):
        def represent(self, **kwargs: Any) -> Any:
            return [{"embedding": embedding}]

    contents, face = profile_image()
    fake = InvalidEmbeddingDeepFace()
    fake.detections = [[face]]
    engine = DeepFaceEngine(Settings(token=TOKEN, warmup=False))
    engine._deepface = fake

    with pytest.raises(FaceServiceError) as caught:
        engine.validate_profile(contents)

    assert caught.value.code == "invalid_profile_photo"
    assert not engine._profile_embeddings
