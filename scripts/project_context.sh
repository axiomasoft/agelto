#!/bin/bash

# Определяем корень проекта (на одну директорию выше, чем папка со скриптом)
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Имя выходного файла
OUTPUT_FILE="$PROJECT_ROOT/project_context.txt"

echo "Собираю контекст проекта в $OUTPUT_FILE..."

# Очищаем файл перед записью
> "$OUTPUT_FILE"

# Запускаем поиск из корня проекта
find "$PROJECT_ROOT" -maxdepth 3 -not -path '*/.*' -type f \( -name "*.php" -o -name "*.yml" \) | while read -r file; do
    # Делаем путь относительным для чистоты логов
    relative_path="${file#$PROJECT_ROOT/}"

    echo "FILE: $relative_path" >> "$OUTPUT_FILE"
    cat "$file" >> "$OUTPUT_FILE"
    echo -e "\n---\n" >> "$OUTPUT_FILE"
done

echo "Готово! Файл сохранен в корне проекта."
