import logging
import math
import sys
import threading
import warnings
from collections import OrderedDict
from hashlib import sha256
from io import BytesIO
from typing import Any

import cv2
import numpy as np
from numpy.typing import NDArray
from PIL import Image, ImageOps, UnidentifiedImageError
from deepface.modules.exceptions import FaceNotDetected, SpoofDetected

from app.config import Settings
from app.exceptions import FaceServiceError


logger = logging.getLogger(__name__)


class DeepFaceEngine:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self._deepface: Any | None = None
        self._model_loaded = False
        self._inference_lock = threading.Lock()
        self._profile_embeddings: OrderedDict[str, list[float]] = OrderedDict()

    @property
    def model_loaded(self) -> bool:
        return self._model_loaded

    def warm_up(self) -> None:
        with self._inference_lock:
            deepface = self._library()
            deepface.build_model(
                model_name=self.settings.model_name,
                task="facial_recognition",
            )
            for backend in self._detector_backends():
                deepface.build_model(model_name=backend, task="face_detector")

            # Building the models does not execute their first forward pass.
            # Prime both batch recognition and anti-spoofing here so users do
            # not pay framework initialization costs on their first clock-in.
            dummy_image = np.zeros((640, 640, 3), dtype=np.uint8)
            deepface.represent(
                img_path=[dummy_image, dummy_image],
                model_name=self.settings.model_name,
                detector_backend="skip",
                enforce_detection=False,
                align=False,
            )
            if self.settings.anti_spoofing:
                anti_spoofing_model = deepface.build_model(
                    model_name="Fasnet",
                    task="spoofing",
                )
                anti_spoofing_model.analyze(
                    img=dummy_image,
                    facial_area=(160, 120, 320, 400),
                )
            self._model_loaded = True

    def validate_profile(self, profile_bytes: bytes) -> dict[str, bool]:
        image = self._decode_image(profile_bytes, profile_photo=True)
        with self._inference_lock:
            deepface = self._library()
            detection = self._detect_single_face(deepface, image, profile_photo=True)
            self._validate_profile_quality(image, detection)

            try:
                embedding = self._represent_face(deepface, self._face_crop(detection))
            except ValueError as exception:
                raise FaceServiceError(
                    "invalid_profile_photo", "Wajah pada foto profil tidak dapat dianalisis dengan jelas."
                ) from exception
            except Exception as exception:
                logger.exception("DeepFace gagal menganalisis foto profil.")
                raise FaceServiceError(
                    "service_unavailable", "Mesin verifikasi wajah gagal memproses foto.", status_code=503
                ) from exception

            self._cache_profile_embedding(sha256(profile_bytes).hexdigest(), embedding)
            self._model_loaded = True

        return {"valid": True}

    def verify(self, profile_bytes: bytes, clock_in_bytes: bytes) -> dict[str, Any]:
        clock_in_image = self._decode_image(clock_in_bytes, profile_photo=False)
        profile_cache_key = sha256(profile_bytes).hexdigest()

        with self._inference_lock:
            deepface = self._library()
            clock_in_face = self._extract_single_face(
                deepface,
                clock_in_image,
                profile_photo=False,
            )
            profile_embedding = self._cached_profile_embedding(profile_cache_key)

            try:
                if profile_embedding is None:
                    profile_image = self._decode_image(profile_bytes, profile_photo=True)
                    profile_face = self._extract_single_face(
                        deepface,
                        profile_image,
                        profile_photo=True,
                    )
                    profile_embedding, clock_in_embedding = self._represent_pair(
                        deepface,
                        profile_face,
                        clock_in_face,
                    )
                    self._cache_profile_embedding(profile_cache_key, profile_embedding)
                else:
                    clock_in_embedding = self._represent_face(deepface, clock_in_face)

                result = deepface.verify(
                    img1_path=profile_embedding,
                    img2_path=clock_in_embedding,
                    model_name=self.settings.model_name,
                    detector_backend="skip",
                    distance_metric=self.settings.distance_metric,
                    enforce_detection=False,
                    align=False,
                    # Always retain the model's pre-tuned distance decision.
                    # A configured distance limit is an additional restriction below.
                    threshold=None,
                    anti_spoofing=False,
                    silent=True,
                )
            except FaceServiceError:
                raise
            except ValueError as exception:
                raise FaceServiceError(
                    "face_not_found",
                    "Wajah tidak dapat dianalisis dengan jelas.",
                ) from exception
            except Exception as exception:
                logger.exception("DeepFace gagal membandingkan dua wajah.")
                raise FaceServiceError(
                    "service_unavailable",
                    "Mesin verifikasi wajah gagal memproses foto.",
                    status_code=503,
                ) from exception

            self._model_loaded = True
            similarity = self._optional_float(result.get("confidence"))
            distance = self._optional_float(result.get("distance"))
            threshold = self._optional_float(result.get("threshold"))
            normalized_similarity = self._percentage(similarity)

            minimum_similarity = max(40.0, self.settings.min_similarity)
            matched = (
                result.get("verified") is True
                and distance is not None
                and threshold is not None
                and 0 <= distance <= threshold
                and normalized_similarity is not None
                and normalized_similarity >= minimum_similarity
                and (
                    self.settings.distance_threshold is None
                    or (
                        distance is not None
                        and distance <= self.settings.distance_threshold
                    )
                )
            )

            return {
                "verified": matched,
                "similarity": normalized_similarity,
                "distance": distance,
                "threshold": threshold,
                "minimum_similarity": minimum_similarity,
                "provider": f"deepface-{self.settings.model_name.lower()}"[:50],
                "model": self.settings.model_name,
                "detector_backend": self.settings.detector_backend,
                "anti_spoofing_enabled": self.settings.anti_spoofing,
            }

    def _extract_single_face(
        self,
        deepface: Any,
        image: NDArray[np.uint8],
        *,
        profile_photo: bool,
    ) -> NDArray[Any]:
        return self._face_crop(self._detect_single_face(deepface, image, profile_photo=profile_photo))

    def _detect_single_face(
        self,
        deepface: Any,
        image: NDArray[np.uint8],
        *,
        profile_photo: bool,
    ) -> dict[str, Any]:
        for backend in self._detector_backends():
            try:
                faces = deepface.extract_faces(
                    img_path=image,
                    detector_backend=backend,
                    enforce_detection=True,
                    align=True,
                    anti_spoofing=self.settings.anti_spoofing and not profile_photo,
                )
            except FaceNotDetected:
                continue
            except SpoofDetected as exception:
                raise FaceServiceError(
                    "spoof_detected",
                    "Foto clock-in tidak lolos pemeriksaan anti-spoofing.",
                ) from exception
            except Exception as exception:
                logger.exception("DeepFace gagal mendeteksi wajah dengan %s.", backend)
                raise FaceServiceError(
                    "service_unavailable",
                    "Mesin pendeteksi wajah gagal memproses foto.",
                    status_code=503,
                ) from exception

            if not faces:
                continue

            # Retry only a missing detection. Multiple faces and spoof failures
            # must never become acceptable through a second detector.
            if len(faces) != 1:
                if profile_photo:
                    raise FaceServiceError(
                        "invalid_profile_photo",
                        "Foto profil harus memuat tepat satu wajah.",
                    )
                raise FaceServiceError(
                    "multiple_faces",
                    "Foto clock-in hanya boleh memuat satu wajah.",
                )

            if (
                self.settings.anti_spoofing
                and not profile_photo
                and faces[0].get("is_real") is not True
            ):
                raise FaceServiceError(
                    "spoof_detected",
                    "Foto clock-in tidak lolos pemeriksaan anti-spoofing.",
                )

            return faces[0]

        raise self._face_detection_error(profile_photo)

    @staticmethod
    def _face_crop(detection: dict[str, Any]) -> NDArray[Any]:
        # DeepFace returns RGB; represent(..., detector_backend="skip") expects BGR.
        return np.ascontiguousarray(detection["face"][:, :, ::-1])

    def _validate_profile_quality(
        self, image: NDArray[np.uint8], detection: dict[str, Any]
    ) -> None:
        area = detection.get("facial_area", {})
        x, y, width, height = (int(area.get(key, 0)) for key in ("x", "y", "w", "h"))
        image_height, image_width = image.shape[:2]
        left = max(0, min(image_width, x))
        top = max(0, min(image_height, y))
        right = max(left, min(image_width, x + width))
        bottom = max(top, min(image_height, y + height))
        crop = image[top:bottom, left:right]
        if min(crop.shape[:2]) < self.settings.profile_min_face_dimension:
            raise FaceServiceError(
                "invalid_profile_photo", "Wajah pada foto profil terlalu kecil. Ambil foto lebih dekat."
            )

        # Score only the detected face, at a fixed size so larger files do not
        # receive a different sharpness threshold simply because of resolution.
        normalized = cv2.resize(crop, (160, 160), interpolation=cv2.INTER_AREA)
        gray = cv2.cvtColor(normalized, cv2.COLOR_BGR2GRAY)
        brightness = float(gray.mean())
        if not self.settings.profile_min_brightness <= brightness <= self.settings.profile_max_brightness:
            raise FaceServiceError(
                "invalid_profile_photo", "Pencahayaan wajah kurang sesuai. Gunakan cahaya yang merata dan cukup."
            )

        sharpness = float(cv2.Laplacian(gray, cv2.CV_64F).var())
        if sharpness < self.settings.profile_min_blur_variance:
            raise FaceServiceError(
                "invalid_profile_photo", "Foto wajah terlalu buram. Ambil foto baru dengan kamera yang fokus."
            )

    def _detector_backends(self) -> list[str]:
        return list(dict.fromkeys(
            backend.strip()
            for backend in (self.settings.detector_backend, self.settings.detector_fallback_backend)
            if backend.strip()
        ))

    def _represent_pair(
        self,
        deepface: Any,
        profile_face: NDArray[Any],
        clock_in_face: NDArray[Any],
    ) -> tuple[list[float], list[float]]:
        representations = deepface.represent(
            img_path=[profile_face, clock_in_face],
            model_name=self.settings.model_name,
            detector_backend="skip",
            enforce_detection=False,
            align=False,
        )

        return (
            self._embedding(representations[0][0]),
            self._embedding(representations[1][0]),
        )

    def _represent_face(
        self,
        deepface: Any,
        face: NDArray[Any],
    ) -> list[float]:
        representations = deepface.represent(
            img_path=face,
            model_name=self.settings.model_name,
            detector_backend="skip",
            enforce_detection=False,
            align=False,
        )

        return self._embedding(representations[0])

    @staticmethod
    def _embedding(representation: dict[str, Any]) -> list[float]:
        embedding = representation.get("embedding")
        if not isinstance(embedding, (list, np.ndarray)):
            raise ValueError("DeepFace tidak mengembalikan embedding wajah yang valid.")

        values = [float(value) for value in embedding]
        if not values or not all(math.isfinite(value) for value in values):
            raise ValueError("Embedding wajah kosong atau tidak valid.")

        return values

    def _cached_profile_embedding(self, key: str) -> list[float] | None:
        embedding = self._profile_embeddings.get(key)
        if embedding is not None:
            self._profile_embeddings.move_to_end(key)

        return embedding

    def _cache_profile_embedding(self, key: str, embedding: list[float]) -> None:
        maximum = self.settings.profile_embedding_cache_size
        if maximum == 0:
            return

        self._profile_embeddings[key] = embedding
        self._profile_embeddings.move_to_end(key)
        while len(self._profile_embeddings) > maximum:
            self._profile_embeddings.popitem(last=False)

    def _decode_image(self, contents: bytes, *, profile_photo: bool) -> NDArray[np.uint8]:
        try:
            with warnings.catch_warnings():
                warnings.simplefilter("error", Image.DecompressionBombWarning)
                with Image.open(BytesIO(contents)) as source:
                    source.load()
                    image = ImageOps.exif_transpose(source).convert("RGB")
        except (Image.DecompressionBombError, Image.DecompressionBombWarning, UnidentifiedImageError, OSError) as exception:
            code = "invalid_profile_photo" if profile_photo else "face_not_found"
            raise FaceServiceError(code, "File foto tidak dapat dibaca.") from exception

        minimum = self.settings.min_image_dimension
        if profile_photo:
            minimum = max(minimum, self.settings.profile_min_image_dimension)
        maximum = self.settings.max_image_dimension
        if image.width < minimum or image.height < minimum:
            code = "invalid_profile_photo" if profile_photo else "face_not_found"
            raise FaceServiceError(
                code,
                f"Resolusi foto minimal {minimum} x {minimum} piksel.",
            )

        if image.width > maximum or image.height > maximum:
            code = "invalid_profile_photo" if profile_photo else "face_not_found"
            raise FaceServiceError(
                code,
                f"Resolusi foto maksimal {maximum} x {maximum} piksel.",
            )

        inference_maximum = self.settings.inference_max_dimension
        if max(image.size) > inference_maximum:
            image.thumbnail(
                (inference_maximum, inference_maximum),
                Image.Resampling.LANCZOS,
            )

        rgb = np.asarray(image)
        return np.ascontiguousarray(rgb[:, :, ::-1])

    def _library(self) -> Any:
        if self._deepface is None:
            self._configure_utf8_output()
            from deepface import DeepFace

            self._deepface = DeepFace

        return self._deepface

    @staticmethod
    def _configure_utf8_output() -> None:
        """Prevent third-party model logs from crashing on Windows cp1252."""
        for stream in (sys.stdout, sys.stderr):
            reconfigure = getattr(stream, "reconfigure", None)
            if callable(reconfigure):
                reconfigure(encoding="utf-8", errors="backslashreplace")

    @staticmethod
    def _face_detection_error(profile_photo: bool) -> FaceServiceError:
        if profile_photo:
            return FaceServiceError(
                "invalid_profile_photo",
                "Wajah pada foto profil tidak terdeteksi dengan jelas.",
            )

        return FaceServiceError(
            "face_not_found",
            "Wajah tidak ditemukan pada foto clock-in.",
        )

    @staticmethod
    def _optional_float(value: Any) -> float | None:
        if value is None:
            return None

        try:
            converted = float(value)
        except (TypeError, ValueError):
            return None

        return converted if math.isfinite(converted) else None

    @staticmethod
    def _percentage(value: float | None) -> float | None:
        if value is None:
            return None

        return max(0.0, min(100.0, value))
