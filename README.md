# Multi-Channel Ads Intelligence & Visual Summary Engine

Open: http://localhost/ads-intel/ (Apache must be running in the XAMPP Control Panel)

## Pipeline (4 steps)
| Step | Prompt | Code | Fixed logic (no AI) |
|---|---|---|---|
| 1 Data Normalization | `prompts/1_normalize.md` | `lib/Normalizer.php` | Clean values, convert currency, compute CTR/CPC/CPA/ROAS, schema check |
| 2 Diagnostic & Anomaly | `prompts/2_diagnose.md` | `lib/Diagnostics.php` | CPA spike, creative fatigue, no conversions, green/yellow/red status |
| 3 Next-Best-Action | `prompts/3_next_best_action.md` | `lib/NextBestAction.php` | Find product × channel gaps; rule-based proposals when Claude is off |
| 4 Visual Report | `prompts/4_visual_report.md` | `lib/ReportRenderer.php`, `lib/Renderer.php` | 1080×1350 template + headless Edge size check & PNG capture |

- AI engine is chosen on the Settings page (`lib/Llm.php`):
  - **Local LLM via Ollama (default, free):** `qwen3:4b`, JSON-schema output, temperature 0, think mode off. Conditions for the local model:
    - Column mapping uses the dictionary first; the model is asked only when a core column is missing.
    - Explanations cover only campaigns that need fixing (max 6).
    - **The model may not write any numbers.** It supplies only the likely cause and next steps; numbers come from code.
    - At most 3 new campaign proposals; the HTML report uses the template.
  - **Claude API:** `claude-opus-5-5` via cURL (`lib/Claude.php`) with structured outputs. It can also design the HTML report.
- Every ROAS/CPA number is computed in PHP from summed totals; the AI never does the arithmetic.
- Without an API key the system still runs in rule mode.

## Eval
- Web: the Eval page · CLI: `C:\xampp\php\php.exe tools\eval_cli.php [--ai] [--claude-report] [-v]`
- Cases: missing values, mixed currencies, zero sales/spend, Thai headers + Buddhist-era dates, layout stress test (40 campaigns with long names)
- Pass criteria: schema valid 100% · ROAS/CPA match hand-computed values · rendered HTML does not overflow 1080×1350 · PNG is 1080×1350

## Files
- `examples/` showcase of one finished run for presentations (input files, every pipeline step, final report). Opens offline. Rebuild with `php tools/make_examples.php [run-id]`
- `samples/` demo data (regenerate with `php tools/make_samples.php`)
- `runs/<id>/` output of each run: `normalized.json`, `result.json`, `report.html`, `report.png`
- `storage/settings.json` API key and settings (blocked from the web)
