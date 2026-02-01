# nedarta/yii2-datetime-behavior

Timezone-aware DateTime behavior for Yii2 ActiveRecord models.

This extension provides a clean, future-proof way to automatically convert datetime values
between **database format** and **user-facing format**, while keeping your database consistent
(UTC / UNIX) and your UI localized.

---

## Features

- Automatic timezone conversion (UI ↔ DB)
- Works on `afterFind` and `beforeSave`
- Supports **UNIX timestamps** and **DATETIME** columns
- Multiple attributes per model
- Ready for multi-timezone users
- Easy to test, no UI or widget coupling
- Compatible with Yii2 Formatter (`asDateTime()`)

---

## Installation

```bash
composer require nedarta/yii2-datetime-behavior
```

---

## Basic Usage

### Model configuration

```php
use nedarta\behaviors\DateTimeBehavior;

class Post extends \yii\db\ActiveRecord
{
    public function behaviors(): array
    {
        return [
            [
                'class' => DateTimeBehavior::class,
                'attributes' => ['created_at', 'scheduled_at'],

                // Database storage
                'dbFormat' => 'unix',          // 'unix' or 'datetime'
                'serverTimeZone' => 'UTC',

                // User-facing format
                'inputFormat' => 'Y-m-d H:i',
                'displayTimeZone' => function() {
                    return Yii::$app->user->identity->timezone ?? 'Europe/Riga';
                },
            ],
        ];
    }
}
```

---

## What Happens Automatically

| Step | Value |
|----|------|
| Database value | `1704031200` |
| After `find()` | `2024-12-31 13:00` |
| User edits | `2025-01-01 09:30` |
| Before `save()` | `1735710600` |

The database always stays in **UTC / UNIX** format.  
The model attribute always contains a **user-facing value**.

---

## Configuration Options

| Option | Type | Default | Description |
|-----|-----|--------|------------|
| `attributes` | `array` | `[]` | Attributes to convert |
| `dbFormat` | `string` | `unix` | `unix` or `datetime` |
| `inputFormat` | `string` | `Y-m-d H:i` | User / UI format |
| `serverTimeZone` | `string` | `UTC` | Database timezone |
| `displayTimeZone` | `string` | `UTC` | User-facing timezone |

---

## How It Works

### DB → UI (`afterFind`)

1. Reads value from database
2. Interprets it using `serverTimeZone`
3. Converts to `displayTimeZone`
4. Formats using `inputFormat`

### UI → DB (`beforeSave`)

1. Parses user input using `inputFormat`
2. Interprets it in `displayTimeZone`
3. Converts to `serverTimeZone`
4. Stores as UNIX timestamp or DATETIME string

---

## Yii2 Formatter Compatibility (`asDateTime()`)

This behavior is fully compatible with `Yii::$app->formatter`.

### Recommended setup

```php
'formatter' => [
    'class' => yii\i18n\Formatter::class,
    'timeZone' => 'Europe/Stockholm',
],
```

Usage:

```php
<?= Yii::$app->formatter->asDateTime($model->published_at) ?>
```

> Important:  
> When using this behavior, model attributes already contain **UI-localized values**.
> Always ensure the formatter timezone matches `displayTimeZone`.

---


## What This Extension Does NOT Do

- Render form inputs or widgets
- Depend on Tempus Dominus or any UI library
- Store business logic
- Guess or auto-detect timezones

This extension operates strictly at the **model layer**.

---

## Recommended Architecture

```
[ UI / Widget ]
       ↓
[ ActiveRecord Attribute ]
       ↓
[ DateTimeBehavior ]
       ↓
[ Database (UTC / UNIX) ]
```

---

## Requirements

- PHP 8.1+
- Yii2 `^2.0`

---

## License

MIT

---

## Roadmap

- PHPUnit test suite
- Read-only / write-only modes
- Per-attribute format overrides
- Optional convention-based trait
- Support for additional database formats (e.g. TIMESTAMP)
- Integration with popular date/time widgets
- Support for batch operations (e.g. `updateAll()`)
