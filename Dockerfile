FROM python:3.12-slim

LABEL org.opencontainers.image.source="https://github.com/fgna/photomap"
LABEL org.opencontainers.image.description="Static photo map builder"
LABEL org.opencontainers.image.licenses="MIT"

WORKDIR /photomap

COPY requirements.txt .
RUN pip install --no-cache-dir Pillow requests pillow-heif

COPY build.py .
COPY assets/ ./assets/

# trips/  → mount your photo folders here
# dist/   → generated site appears here
# Geocoding cache persists if you mount a file at /photomap/build-cache.json
VOLUME ["/photomap/trips", "/photomap/dist"]

ENTRYPOINT ["python", "build.py"]
CMD ["--trips-dir", "/photomap/trips", \
     "--output",    "/photomap/dist", \
     "--cache",     "/photomap/build-cache.json"]
