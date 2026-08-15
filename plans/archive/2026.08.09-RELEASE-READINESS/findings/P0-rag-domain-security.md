# P0.5 — Domain and security RAG

## Scope and queries

Проверка выполнена 2026-08-10 для Laravel 13.24/Guzzle 7 runtime, project proxy contract,
archive and file-routing safety, procurement-document terminology and estimate formats. Local
facts взяты из declared P0.5 inputs only: D8, `ProxyResolver`, `ArchiveStrategy`, typed analyzer
registry and document workflow. External queries were split by proxy options, package
compatibility, MIME registry, archive threats, construction-estimate terminology and GSFX
provenance. WebSearch is the RAG fallback because Context7/perplexity-web were unavailable.

Output contract: claim → applicable scope/version → primary URL → failure scenario → consumer;
unselected dependency, unregistered media type or issuer-less spelling remains `[UNVERIFIED]`.

## Sources

| ID | Primary source | Checked | Applicable scope | Confidence |
|:--|:--|:--|:--|:--|
| S1 | Guzzle, [Request options](https://docs.guzzlephp.org/en/stable/request-options.html) | 2026-08-10 | Guzzle 7 proxy and TLS options below Laravel HTTP client | high |
| S2 | Laravel, [HTTP Client API](https://api.laravel.com/docs/13.x/Illuminate/Http/Client/Factory.html) | 2026-08-10 | Laravel 13 HTTP request/global options carrier | medium |
| S3 | IANA, [Media Types registry](https://www.iana.org/assignments/media-types/media-types.xhtml) | 2026-08-10 | registered MIME media types and standards authority | high |
| S4 | OWASP, [Malicious-file upload testing](https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/10-Business_Logic_Testing/09-Test_Upload_of_Malicious_Files) | 2026-08-10 | archive symlink and decompression-bomb threats | high |
| S5 | OWASP, [ASVS file-upload requirement](https://cornucopia.owasp.org/taxonomy/asvs-4.0.3/12-files-and-resources/01-file-upload) | 2026-08-10 | pre-extraction expanded-size and file-count limits | high |
| S6 | Минстрой России, [XML format for local estimates](https://minstroyrf.gov.ru/tim/xml-skhemy/format-predstavleniya-lokalnykh-smetnykh-raschetov-smet-v-elektronnom-vide-na-osnove-xml/p2_1/) | 2026-08-10 | local estimates in XML | medium |
| S7 | Минстрой России, [Pricing authority](https://minstroyrf.gov.ru/trades/tsenoobrazovanie/) | 2026-08-10 | construction cost-estimate terminology and methodology | medium |
| S8 | ГРАНД-Смета, [Objects and estimates course](https://ftp.grandsmeta.ru/sub/videocourse/obekty-i-smety.pdf) | 2026-08-10 | vendor-native `.gsfx` document format | medium |
| S9 | Казначейство России, [EIS procurement system](https://roskazna.gov.ru/gis/eis-zakupki-gov-ru) | 2026-08-10 | procurement-system provenance | medium |

## Verified findings

### DOM-01 — proxy policy belongs above transport options

Guzzle 7 supports a per-request `proxy` option and makes an explicit `no` list the caller's
responsibility; disabling TLS verification is documented as insecure. Laravel 13's HTTP factory
supplies request/global options but does not define business precedence or health policy. D8's
`profile proxy → optional default proxy → direct` rule is therefore a valid application policy
above the transport, provided only `ProxyResolver` creates proxy leases and `SourceTlsTrust`
remains the TLS-trust owner. **Verdict: CONFIRMED.** RAG:✅ 2026-08-10, S1/S2; local evidence:
D8 and `app/Services/Procurement/Proxy/ProxyResolver.php`. Confidence: high.

Failure scenario: a call site hands a raw proxy URL or `verify => false` directly to Guzzle,
bypassing scope, target health, no-proxy policy or the sole TLS-trust boundary. Consumers: P5.1–P5.5,
P10.2, P12.4.

### DOM-02 — settings package is not yet a compatibility fact

No `spatie/laravel-settings` dependency or selected version appears in the declared local input.
Its Laravel 13 compatibility, migration lifecycle, cache/worker freshness behaviour and
configuration surface cannot be inferred from other Spatie packages. **Verdict: [UNVERIFIED].**
P5.2 must name a package/version and verify its own official Composer constraints before adding it.
RAG:✅ 2026-08-10, S2. Confidence: insufficient by design.

Failure scenario: a settings carrier is installed on the assumption that any current Spatie package
supports Laravel 13, or workers retain stale proxy configuration after mutation. Consumers: P5.2–P5.5,
P11.2, P12.4.

### DOM-03 — extension, MIME and magic are independent evidence classes

IANA is the authority for registered media types, but it does not make a filename extension a
trustworthy content proof and it has no registered claim for vendor-private formats by filename.
The typed strategy/registry boundary must use extension as routing metadata, MIME and
magic/content inspection as independent inputs, and retain an explicit unsupported outcome rather
than generic “office document” dispatch. **Verdict: CONFIRMED** for the three-evidence boundary;
exact per-format matrix remains P6.1/P6.2. RAG:✅ 2026-08-10, S3; local evidence:
`DocumentAnalyzerRegistry` and `docs/02_Workflow/document-and-estimate-pipeline.md`. Confidence: high.

Failure scenario: a renamed archive or executable is routed into a parser based only on its suffix,
or a registered MIME string is mistaken for proof of the purported vendor format. Consumers: P6.1–P6.6,
P7.2–P7.4, P10.2.

### DOM-04 — archive admission needs explicit limits before extraction

OWASP identifies archive symlinks and decompression bombs as file-processing threats, and ASVS
requires checks of maximum expanded size and file count before decompression. The current strategy
already lists entries before materialization and sets `max_depth=2`, `max_files=200` and
`max_total_mb=500`; it also rejects traversal names. These are necessary limits, not proof that
each extractor rejects symlinks, device names, hard links, duplicate normalized paths, per-entry
oversized output or runtime/CPU exhaustion. **Verdict: CONFIRMED** for the threat and aggregate
limits; **[UNVERIFIED]** for extractor-specific enforcement until P6.3 tests. RAG:✅ 2026-08-10,
S4/S5; local evidence: `ArchiveStrategy.php`, `config/ai.php`. Confidence: high.

Failure scenario: a small compressed upload expands beyond disk/memory budget, writes through a
symlink or traversal path, or causes unbounded work despite aggregate header limits. Consumers:
P6.1–P6.5, P10.2/P10.4, P12.4.

### DOM-05 — procurement taxonomy and estimate format provenance stay separate

Минстрой publishes an XML format specifically for local estimate calculations (сметы) and owns
construction-cost methodology; it does not make every procurement attachment a local estimate.
The EIS is the authoritative system boundary for procurement information, while the local workflow
already distinguishes procurement documents from estimate extraction. **Verdict: CONFIRMED** for
“procurement document → possible estimate → format-specific representation,” and **REFUTED** for
collapsing procurement-document taxonomy into an estimate-format enum. RAG:✅ 2026-08-10, S6/S7/S9;
local evidence: document workflow. Confidence: medium.

Failure scenario: an извещение, protocol, contract or technical attachment is treated as a broken
estimate rather than screened/classified as its own document type. Consumers: P7.1–P7.6, P8.1–P8.5,
P10.3.

### DOM-06 — GSFX is vendor evidence; JSRF/GSRF are not aliases

Official ГРАНД-Смета material names `.gsfx` as the program's document format and separately
describes XML conversion. It does not establish `JSRF` or `GSRF` as spellings/aliases for `.gsfx`,
and IANA does not provide a registered media type that resolves that ambiguity. **Verdict:
CONFIRMED** for `.gsfx` vendor provenance; **[UNVERIFIED]** for `JSRF`, `GSRF` and any `GSFX`
normalization beyond literal `.gsfx`. RAG:✅ 2026-08-10, S3/S8. Confidence: medium.

Failure scenario: a typo or unrelated private format is silently accepted as GSFX, producing a
misleading parser result instead of a visible unsupported-format disposition. Consumers: P6.1–P6.3,
P7.1/P7.6, P10.2.

## Contradictions and rejected claims

| ID | Local/provisional claim | Verdict | Evidence-backed consequence |
|:--|:--|:--|:--|
| C1 | Any transport call may choose its own proxy and TLS setting. | REFUTED | Keep D8 precedence above Guzzle; use `ProxyResolver`; never use `verify => false`. |
| C2 | A Spatie Settings carrier is known Laravel 13-compatible before selection. | UNVERIFIED | P5.2 must verify named package/version and worker freshness. |
| C3 | MIME or extension alone identifies a safe parser. | REFUTED | P6 requires extension + MIME + magic/content policy and explicit unsupported reasons. |
| C4 | Count and total expanded bytes prove safe archive extraction. | REFUTED | P6.3 must test traversal, links, depth, duplicate paths, per-entry limits and resource exhaustion. |
| C5 | Every procurement attachment is an estimate. | REFUTED | Preserve document taxonomy and screening disposition independently of estimate extraction. |
| C6 | JSRF/GSRF are aliases for GSFX. | UNVERIFIED | Do not normalize; obtain issuer specification/sample corpus first. |

## Recommendations

1. **P5.1–P5.5:** keep D8; let settings feed only profile/default policy, preserve global/per-target
   health and make absent policy explicitly direct—not an accidental transport fallback.
2. **P6.1/P6.2:** freeze a three-signal format matrix: suffix, accepted MIME/magic evidence,
   issuer/specification, handler and unsupported reason; private formats remain literal.
3. **P6.3–P6.5:** enforce/test depth, entry count, aggregate and per-entry expanded bytes,
   normalized target uniqueness, containment, symlink/link rejection, timeout and disk-quota failure.
   Current `2`/`200`/`500 MiB` are validation inputs, not universal safe values.
4. **P7/P8:** keep document taxonomy above formats; a construction estimate is a possible
   classified document, while notices, protocols, contracts and attachments stay first-class outcomes.

## Open questions

- `[UNVERIFIED]` exact Spatie Settings release and Laravel 13/queue-worker refresh contract.
- `[UNVERIFIED]` issuer specification/sample corpus for `JSRF` and `GSRF`; neither is a GSFX alias.
- `[UNVERIFIED]` ZIP/7z/RAR adapters' handling of links, duplicate normalized paths, per-entry limits
  and CPU/time budgets requires P6.3 adversarial evidence.
- Owner/design choice for P7: taxonomy mapping for platform-specific procurement attachments.

### Verification status

- RAG: WebSearch fallback ×16 OK; Guzzle 7, Laravel 13 API, IANA, OWASP, Минстрой, Казначейство
  and ГРАНД-Смета sources checked 2026-08-10.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: named Spatie Settings package/constraint, JSRF/GSRF issuer
  provenance and real archive-adapter behaviour; consumers P5.2, P6.3 and P7.1.
- Обходки/[POSSIBLE-DEFECT]: no workaround accepted; this read-only research changed no format alias,
  archive implementation or proxy setting.
