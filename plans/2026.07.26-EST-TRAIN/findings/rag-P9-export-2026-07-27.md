# RAG — форматы обучающих датасетов под локальную LLM (P9.1)

> Слой 1 (`plan-protocol` §16): внешняя фактура, полученная в дизайне фазы P9.
> Дата запроса: 2026-07-27 · Движок: Perplexity Web · Питает: P9.1, D75, D76.

## 1. Вопрос

Чем должен быть `ai:dataset:export`, чтобы его выход брал реальный training-раннер БЕЗ
конвертера: какой формат SFT-строки и какой формат preference-пары ждут TRL / Axolotl / Unsloth
на дообучении Qwen3 QLoRA (стек, зафиксированный EST-AI D16).

## 2. Вердикт

| Формат | Канон | Наш статус |
|:--|:--|:--|
| SFT (instruction tuning) | conversational JSONL: `{"messages": [{"role": …, "content": …}, …]}` — TRL `SFTTrainer` применяет chat-template модели сам; Axolotl рекомендует тот же OpenAI-messages формат для Qwen3 | ✅ уже так (`DatasetExportCommand::writeSft()`) |
| DPO (preference) | поля `prompt` / `chosen` / `rejected`; TRL `DPOTrainer` принимает ОБА представления — «standard» (плоские строки) и «conversational» (списки сообщений, шаблон применяется автоматически) | ✅ имена полей те же; представление — предмет решения D76 |

Дословный вывод источника: «default to `messages` for SFT and `prompt`/`chosen`/`rejected` for DPO,
then let Axolotl or Unsloth apply the Qwen3 chat template during training»; «DPOTrainer supports
both standard and conversational dataset formats».

**Источники:** docs.axolotl.ai/docs/rlhf.html (DPO-датасеты и preference-форматы Axolotl),
документация TRL по dataset formats и `DPOTrainer`, Qwen3-руководства Axolotl и Unsloth
(QLoRA + preference-optimization) — сводка Perplexity Web от 2026-07-27.

## 3. Что из этого следует для плана

1. **Имена полей менять не нужно** — сегодняшний экспорт EST-AI уже попадает в канон обоих
   форматов. Работа P9.1 — не переизобретение формата, а НАПОЛНЕНИЕ его содержимым
   (представление документа в `user`-турне) и честный отбор записей.
2. **`prompt` DPO-пары обязан нести тот же вход, что SFT.** Сегодня там строка
   `"document_version_id=<id>"` — preference-сигнал учится без инструкции и без документа, то
   есть пара обучает «предпочитай этот JSON» вне всякого контекста. Источник прямо описывает
   preference-пример как «prompt + предпочтённое и отвергнутое ПРОДОЛЖЕНИЕ» — продолжение чего
   именно, задаёт `prompt`.
3. **Неоднозначность названа честно:** какой из двух вариантов DPO (плоские строки против списков
   сообщений) возьмёт конкретный раннер, зависит от его loader'а — источник говорит «depending on
   the trainer path and dataset loader». Поэтому выбор варианта не зашивается: он остаётся ключом
   `--dpo-schema` и записывается в манифест экспорта (D76), а конвертер между вариантами
   тривиален и живёт на стороне обучения.
4. **Chat-template применяет раннер, не мы.** Экспорт НЕ склеивает `<|im_start|>`-разметку Qwen3 и
   вообще не знает о токенизаторе целевой модели — иначе датасет протухнет при смене модели.
