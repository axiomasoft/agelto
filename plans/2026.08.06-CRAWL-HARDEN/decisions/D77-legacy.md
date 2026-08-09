---
id: D77
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Витрина эталонных ответов (`prc_ai_dataset_examples`) становится READ-ONLY: страницы создания и правки удаляются, а не прячутся.** Авторское решение при детализации: канон проекта называет `Ai\DatasetExample` immutable gold-снапшотом обучающего контура, а админка сегодня позволяет создать и отредактировать его формой — то есть поменять эталон обучения мимо файла и мимо команды импорта. Отвергнуто: оставить правку «на всякий случай» (это ровно тот путь, которым эталон перестаёт быть эталоном). **Consequences:** сценарий «поправить снимок руками из админки» исчезает; исправление эталона идёт через файл корпуса и `estimate:gold:import`, то есть дольше и через git

## Почему

`RAG:— (repo-grounded: CLAUDE.md, раздел «AI-подомен»; app/Filament/Resources/Procurement/Ai/DatasetExamples/Schemas/DatasetExampleForm.php:18-34; app/Services/Procurement/Analyzers/Estimate/GoldExampleRecorder.php:31)`
