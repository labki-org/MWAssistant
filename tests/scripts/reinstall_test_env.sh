#!/bin/bash
set -e

echo "==> Shutting down existing containers and removing volumes..."
docker compose down -v

echo "==> Starting new environment..."
docker compose up -d

echo "==> Environment ready!"
echo "Visit http://localhost:8890"
