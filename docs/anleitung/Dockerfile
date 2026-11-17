# Bauumgebung für die Anleitung: WeasyPrint (PDF) und Pillow (Bilder). Nur lokal nötig, nie im Release.
FROM python:3.13-slim
RUN apt-get update && apt-get install -y --no-install-recommends libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz0b libffi8 fontconfig qpdf \
    && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir weasyprint==66.0 pillow markdown-it-py
WORKDIR /anleitung
