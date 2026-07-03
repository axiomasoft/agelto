#!/bin/bash

# Определяем корень проекта (на одну директорию выше, чем папка со скриптом)
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Имя выходного файла
OUTPUT_FILE="$PROJECT_ROOT/project_context.txt"

echo "Собираю контекст проекта в $OUTPUT_FILE..."

# Очищаем файл перед записью
> "$OUTPUT_FILE"

# Запускаем поиск из корня проекта БЕЗ ограничения глубины
# Исключаем служебные, тяжелые и скрытые папки
find "$PROJECT_ROOT" \
    -not -path '*/.*' \
    -not -path '*/stubs/*' \
    -not -path '*/vendor/*' \
    -not -path '*/node_modules/*' \
    -not -path '*/storage/*' \
    -not -path '*/public/build/*' \
    -not -path '*/public/storage/*' \
    -type f \( -name "*.php" -o -name "*.yml" -o -name "*.yaml" \) | while read -r file; do

    # Делаем путь относительным для чистоты логов и удобства AI
    relative_path="${file#$PROJECT_ROOT/}"

    echo "FILE: $relative_path" >> "$OUTPUT_FILE"
    cat "$file" >> "$OUTPUT_FILE"
    echo -e "\n---\n" >> "$OUTPUT_FILE"
done

echo "Готово! Файл сохранен в: $OUTPUT_FILE"
