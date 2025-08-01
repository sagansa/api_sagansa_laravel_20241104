# Face Recognition Microservice

A lightweight Python microservice for face encoding generation and comparison using the free `face_recognition` library.

## Features

- Face encoding generation from images
- Face encoding comparison with confidence scores
- Batch comparison support
- Health check endpoint
- Error handling and logging

## Installation

1. Install Python dependencies:
```bash
pip install -r requirements.txt
```

2. Run the service:
```bash
python app.py
```

The service will start on `http://localhost:5000` by default.

## Environment Variables

- `PORT`: Service port (default: 5000)
- `DEBUG`: Enable debug mode (default: False)

## API Endpoints

### Health Check
```
GET /health
```

### Generate Face Encoding
```
POST /generate_encoding
Content-Type: multipart/form-data

Form data:
- image: Image file (PNG, JPG, JPEG, GIF, BMP)
```

### Compare Face Encodings
```
POST /compare_encodings
Content-Type: application/json

{
  "encoding1": [128-dimensional array],
  "encoding2": [128-dimensional array]
}
```

### Batch Compare
```
POST /batch_compare
Content-Type: application/json

{
  "target_encoding": [128-dimensional array],
  "encodings": [[128-dimensional array], ...]
}
```

## Usage with Laravel

The Laravel `FaceRecognitionService` automatically communicates with this microservice. Make sure the service is running before using face recognition features.

## Docker Support

You can also run this service in Docker:

```dockerfile
FROM python:3.9-slim

WORKDIR /app

# Install system dependencies for dlib
RUN apt-get update && apt-get install -y \
    cmake \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install -r requirements.txt

COPY app.py .

EXPOSE 5000

CMD ["python", "app.py"]
```