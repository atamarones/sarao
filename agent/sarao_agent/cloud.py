"""Cliente HTTPS de la API del agente en la nube (docs/karaoke-contrato-agente.md)."""

from __future__ import annotations

import json
import logging
import urllib.error
import urllib.request

from . import __version__

log = logging.getLogger(__name__)


class CloudError(Exception):
    def __init__(self, message: str, status: int | None = None, retryable: bool = True):
        super().__init__(message)
        self.status = status
        self.retryable = retryable


class CloudClient:
    def __init__(self, url: str, token: str, timeout_s: float = 20.0):
        if not url.startswith("https://") and not url.startswith("http://127.0.0.1"):
            raise ValueError("La URL de la nube debe ser https:// (http solo para pruebas locales)")
        self.url = url
        self.token = token
        self.timeout_s = timeout_s

    def call(self, action: str, body: dict | None = None) -> dict:
        data = json.dumps(body or {}, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(
            f"{self.url}?action={action}",
            data=data,
            method="POST",
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "Authorization": f"Bearer {self.token}",
                "User-Agent": f"sarao-agent/{__version__}",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=self.timeout_s) as resp:  # noqa: S310 - URL de configuración
                raw = resp.read()
        except urllib.error.HTTPError as exc:
            detail = ""
            try:
                detail = json.loads(exc.read() or b"{}").get("detail", "")
            except ValueError:
                pass
            # 401/403/404/422 no se arreglan reintentando; 5xx y 429 sí.
            retryable = exc.code >= 500 or exc.code == 429
            raise CloudError(f"{action}: HTTP {exc.code} {detail}".strip(), exc.code, retryable) from None
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            raise CloudError(f"{action}: sin conexión con la nube ({exc})") from None
        try:
            return json.loads(raw or b"{}")
        except ValueError:
            raise CloudError(f"{action}: respuesta no es JSON") from None
