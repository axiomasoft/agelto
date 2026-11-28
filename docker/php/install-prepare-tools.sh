#!/bin/sh
set -eu

apt-get update
apt-get install -y --no-install-recommends \
    qpdf libreoffice-calc libreoffice-writer fonts-dejavu-core fonts-liberation \
    7zip unar poppler-utils tesseract-ocr tesseract-ocr-rus tesseract-ocr-eng \
    ocrmypdf ghostscript
apt-get clean
rm -rf /var/lib/apt/lists/*
