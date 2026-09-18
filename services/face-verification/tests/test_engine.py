from io import BytesIO
from typing import Any

import numpy as np
from PIL import Image

from app.config import Settings
from app.services.deepface_engine import DeepFaceEngine


TOKEN = "test-token-with-at-least-32-characters"


class FakeDeepFace:
    def __init__(self, *, verified: bool = True, confidence: float | None = 91.5) -> None:
        self.extract_calls: list[dict[str, Any]] = []
        self.represent_calls: list[dict[str, Any]] = []
        self.verify_calls: list[dict[str, Any]] = []
        self.verified = verified
        self.confidence = confidence

    def extract_faces(self, **kwargs: Any) -> list[dict[str, Any]]:
        self.extract_calls.append(kwargs)
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
    fake = FakeDeepFace(confidence=63.0)
    engine = DeepFaceEngine(
        Settings(token=TOKEN, warmup=False, min_similarity=70.0)
    )
    engine._deepface = fake

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is False
    assert result["similarity"] == 63.0
    assert result["minimum_similarity"] == 70.0


def test_model_match_at_minimum_similarity_is_accepted() -> None:
    fake = FakeDeepFace(confidence=70.0)
    engine = DeepFaceEngine(
        Settings(token=TOKEN, warmup=False, min_similarity=70.0)
    )
    engine._deepface = fake

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is True


def test_model_match_without_similarity_is_rejected() -> None:
    fake = FakeDeepFace(confidence=None)
    engine = DeepFaceEngine(
        Settings(token=TOKEN, warmup=False, min_similarity=70.0)
    )
    engine._deepface = fake

    result = engine.verify(image_bytes(800, 600), image_bytes(800, 600))

    assert result["verified"] is False
    assert result["similarity"] is None


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
