#!/usr/bin/env python3
"""
Face Recognition Microservice
A lightweight Python service for face encoding generation and comparison.
Uses the free face_recognition library.
"""

import os
import json
import numpy as np
from flask import Flask, request, jsonify
from werkzeug.utils import secure_filename
import face_recognition
from PIL import Image
import io
import logging

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 16 * 1024 * 1024  # 16MB max file size

# Allowed image extensions
ALLOWED_EXTENSIONS = {'png', 'jpg', 'jpeg', 'gif', 'bmp'}

def allowed_file(filename):
    """Check if file extension is allowed."""
    return '.' in filename and \
           filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS

def load_image_from_request(request_file):
    """Load and validate image from request."""
    try:
        # Read image data
        image_data = request_file.read()
        
        # Open image with PIL
        pil_image = Image.open(io.BytesIO(image_data))
        
        # Convert to RGB if necessary
        if pil_image.mode != 'RGB':
            pil_image = pil_image.convert('RGB')
        
        # Convert PIL image to numpy array
        image_array = np.array(pil_image)
        
        return image_array
    except Exception as e:
        logger.error(f"Error loading image: {str(e)}")
        return None

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint."""
    return jsonify({
        'status': 'healthy',
        'service': 'face_recognition',
        'version': '1.0.0'
    })

@app.route('/generate_encoding', methods=['POST'])
def generate_encoding():
    """Generate face encoding from uploaded image."""
    try:
        # Check if image file is present
        if 'image' not in request.files:
            return jsonify({
                'error': 'No image file provided'
            }), 400
        
        file = request.files['image']
        
        if file.filename == '':
            return jsonify({
                'error': 'No file selected'
            }), 400
        
        # Load image
        image_array = load_image_from_request(file)
        if image_array is None:
            return jsonify({
                'error': 'Invalid image format'
            }), 400
        
        # Find face locations
        face_locations = face_recognition.face_locations(image_array)
        
        if len(face_locations) == 0:
            return jsonify({
                'error': 'No face detected in image',
                'encoding': []
            }), 200
        
        if len(face_locations) > 1:
            logger.warning(f"Multiple faces detected ({len(face_locations)}), using the first one")
        
        # Generate face encoding for the first face found
        face_encodings = face_recognition.face_encodings(image_array, face_locations)
        
        if len(face_encodings) == 0:
            return jsonify({
                'error': 'Could not generate face encoding',
                'encoding': []
            }), 200
        
        # Convert numpy array to list for JSON serialization
        encoding = face_encodings[0].tolist()
        
        return jsonify({
            'success': True,
            'encoding': encoding,
            'faces_detected': len(face_locations)
        })
        
    except Exception as e:
        logger.error(f"Error generating face encoding: {str(e)}")
        return jsonify({
            'error': 'Internal server error',
            'message': str(e)
        }), 500

@app.route('/compare_encodings', methods=['POST'])
def compare_encodings():
    """Compare two face encodings and return confidence score."""
    try:
        data = request.get_json()
        
        if not data or 'encoding1' not in data or 'encoding2' not in data:
            return jsonify({
                'error': 'Both encoding1 and encoding2 are required'
            }), 400
        
        encoding1 = np.array(data['encoding1'])
        encoding2 = np.array(data['encoding2'])
        
        # Validate encoding dimensions
        if encoding1.shape != (128,) or encoding2.shape != (128,):
            return jsonify({
                'error': 'Invalid encoding format. Expected 128-dimensional arrays.'
            }), 400
        
        # Calculate face distance (lower is better)
        face_distance = face_recognition.face_distance([encoding1], encoding2)[0]
        
        # Convert distance to confidence (higher is better)
        # face_recognition typically uses 0.6 as threshold
        # We'll convert distance to confidence score (0-1)
        confidence = max(0.0, 1.0 - face_distance)
        
        # Also provide the raw distance for debugging
        return jsonify({
            'success': True,
            'confidence': float(confidence),
            'distance': float(face_distance),
            'is_match': face_distance < 0.6  # Standard threshold
        })
        
    except Exception as e:
        logger.error(f"Error comparing face encodings: {str(e)}")
        return jsonify({
            'error': 'Internal server error',
            'message': str(e)
        }), 500

@app.route('/batch_compare', methods=['POST'])
def batch_compare():
    """Compare one encoding against multiple encodings."""
    try:
        data = request.get_json()
        
        if not data or 'target_encoding' not in data or 'encodings' not in data:
            return jsonify({
                'error': 'target_encoding and encodings array are required'
            }), 400
        
        target_encoding = np.array(data['target_encoding'])
        encodings = [np.array(enc) for enc in data['encodings']]
        
        # Validate target encoding
        if target_encoding.shape != (128,):
            return jsonify({
                'error': 'Invalid target encoding format'
            }), 400
        
        results = []
        
        for i, encoding in enumerate(encodings):
            if encoding.shape != (128,):
                results.append({
                    'index': i,
                    'error': 'Invalid encoding format'
                })
                continue
            
            face_distance = face_recognition.face_distance([target_encoding], encoding)[0]
            confidence = max(0.0, 1.0 - face_distance)
            
            results.append({
                'index': i,
                'confidence': float(confidence),
                'distance': float(face_distance),
                'is_match': face_distance < 0.6
            })
        
        return jsonify({
            'success': True,
            'results': results
        })
        
    except Exception as e:
        logger.error(f"Error in batch comparison: {str(e)}")
        return jsonify({
            'error': 'Internal server error',
            'message': str(e)
        }), 500

if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5000))
    debug = os.environ.get('DEBUG', 'False').lower() == 'true'
    
    logger.info(f"Starting Face Recognition Service on port {port}")
    app.run(host='0.0.0.0', port=port, debug=debug)