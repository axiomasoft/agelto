---
audience: dev
type: explanation
status: draft
---

# Обучающий контур

Здесь будет: analysis → review → correction → re-run → gold → export SFT/DPO (D15), публичная
часть локального LLM-стека (детали — `docs-private/local-llm-training.md`).

## Д5 — обучающий контур

```mermaid
flowchart LR
    Analysis["Analysis (succeeded)<br/>review_status=unreviewed"]
    Review{"Оператор ревьюит<br/>(Filament AnalysisInfolist)"}
    Approved["review_status=approved"]
    Rejected["review_status=rejected<br/>+ Correction (field-level, JSONPath+reason-enum)"]
    Rerun["RerunAnalysisAction<br/>(app/Services/Procurement/Extraction/RerunAnalysisAction.php)"]
    Gold["GoldExampleRecorder<br/>(app/Services/Procurement/Extraction/GoldExampleRecorder.php)<br/>immutable snapshot prc_ai_dataset_examples"]
    Export["DatasetExportCommand<br/>(app/Console/Commands/Ai/DatasetExportCommand.php)"]
    SFT["--format=sft<br/>JSONL chat-format (Qwen3 QLoRA)"]
    DPO["--format=dpo<br/>chosen(gold)/rejected(Analysis.result предшественника)"]

    Analysis --> Review
    Review --> Approved
    Review --> Rejected
    Approved --> Gold
    Rejected --> Rerun
    Rerun -->|"parent_analysis_id, коррекции applied_at,<br/>ScreeningJob/EstimateExtractionJob передиспатч"| Analysis
    Gold --> Export
    Export --> SFT
    Export --> DPO
```

`source` gold-примера: `corrected` — если у прямого предшественника анализа есть применённая
коррекция (`applied_at` не null); иначе `llm` (approve сырого вывода без цикла правок).
Скрининг (`AnalysisStep::Screening`) не производит `estimates[]`-контракт — петля покрывает только
извлечение смет (`GoldExampleRecorder`, докблок класса).

Наполняется в P10.3 (развёрнутый текст, связка с `docs-private/local-llm-training.md`).
