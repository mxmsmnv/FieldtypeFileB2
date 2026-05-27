# FieldtypeFileB2 — Project Rules for Claude

## Versioning & Changelog

**При каждом изменении кода:**

1. Обновлять версию по SemVer (`Major.Minor.Patch`) в **обоих** `getModuleInfo()`:
   - `FieldtypeFileB2.module.php`
   - `InputfieldFileB2.module.php`

2. Добавлять новую запись в `CHANGELOG.md` с датой и кратким описанием правок.

### Правила выбора версии

| Масштаб изменений | Компонент | Пример |
|---|---|---|
| Несовместимое изменение API / схемы БД | **Major** | `1.x.x → 2.0.0` |
| Новая функциональность, обратно совместимая | **Minor** | `1.0.x → 1.1.0` |
| Исправление бага, рефакторинг без новых фич | **Patch** | `1.1.x → 1.1.1` |

### Формат записи в CHANGELOG.md

```
## [X.Y.Z] - YYYY-MM-DD

### Added / Fixed / Changed / Removed
- Краткое описание на русском или английском
```

### Версия в getModuleInfo()

ProcessWire хранит версию как целое число: `Major * 100 + Minor * 10 + Patch`.
Примеры: `1.0.0 → 100`, `1.1.0 → 110`, `1.2.3 → 123`, `2.0.0 → 200`.

```php
'version' => 110, // = 1.1.0
```
