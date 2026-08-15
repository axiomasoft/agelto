# P0 RAG provider runtime — 2026-08-10

## Scope

Read-only диагностика escalation P0.3. Проверялись только доступность MCP tools, daemon logs,
graphical environment и persistent-profile ownership. Product files, user MCP configuration,
browser profile и lock-файлы не изменялись.

## Observed evidence

- Codex tool `mcp__perplexity_web__login` вернул `Daemon connection closed before result`.
- `/run/user/1000/perplexity-web-mcp/default.log` фиксирует запуск pool `default`, затем
  `Missing X server or $DISPLAY` / `Invalid MIT-MAGIC-COOKIE-1 key` при headed Chromium.
- `/tmp/perplexity-web-mcp/default.log` фиксирует повторные `Failed to create a
  ProcessSingleton` для общего profile
  `/home/vostrikov/projects/packages/perplexity-web-mcp/.playwright/profile`.
- На момент диагностики `DISPLAY=:0`, `XAUTHORITY` указывал на auth-файл активного Xwayland,
  одновременно существовало несколько MCP client processes `dist/index.js --pool=default`.
- `SingletonLock` был dangling symlink на отсутствующий PID; файл не удалялся.
- Playwright Chromium установлен; класс ошибки не связан с отсутствующим browser binary.

## Verdict

`perplexity-web` присутствует в tool catalog, но текущая session capability фактически
неработоспособна. Root cause class — stale graphical environment plus shared persistent-profile
collision across runtimes; это не доказательство дефекта P0.3 research spec. RAG:—
(repo-grounded runtime evidence: daemon logs 2026-08-10).

## Safe continuation boundary

1. Завершить/перезапустить MCP-owning sessions так, чтобы новый Codex process унаследовал текущие
   `DISPLAY`/`XAUTHORITY` и один daemon владел pool/profile.
2. В новой сессии вызвать `mcp__perplexity_web__login`.
3. Выполнить один focused `mcp__perplexity_web__search`; ответ должен содержать cited sources.
4. Только после обоих зелёных smoke steps запускать P0.3 replay по D56.

Удаление lock, правка `~/.codex/config.toml`, смена pool/profile или правка
`perplexity-web-mcp` — внешняя mutation вне scope этого plan-design и требует отдельной команды
владельца.
