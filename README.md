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
- **Timezone offset inclusion** for robust Formatter integration
- Includes `toTimestamp()` helper for UTC-normalized timestamps
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
                'dbFormat' => 'unix',          // 'unix', 'datetime', 'date', 'time', or custom format like 'Ymd'
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

| Step | Value | Description |
|----|------|-------------|
| Database value | `1704031200` | UTC Unix Timestamp |
| After `find()` | `2023-12-31 16:00 +02:00` | Local time with offset |
| User edits | `2024-01-01 10:00` | UI Form input (no offset) |
| Before `save()` | `1704103200` | Converted back to UTC |

The database always stays in **UTC / UNIX** format.  
The model attribute always contains a **user-facing value**.

---

## Configuration Options

| Option | Type | Default | Description |
|-----|-----|--------|------------|
| `attributes` | `array` | `[]` | Attributes to convert |
| `dbFormat` | `string` | `unix` | `unix`, `datetime`, `date`, `time`, or custom PHP format string (e.g. `Y-m-d`) |
| `inputFormat` | `string` | `Y-m-d H:i` | User / UI format |
| `serverTimeZone` | `string` | `UTC` | Database timezone |
| `displayTimeZone` | `string|null` | `null` | User-facing timezone. If `null`, falls back to `Yii::$app->formatter->timeZone` or `Yii::$app->timeZone`. |

---

## How It Works

### DB → UI (`afterFind`)

1. Reads value from database
2. Interprets it using `serverTimeZone`
3. Converts to `displayTimeZone`
4. Formats as `inputFormat` + `P` (timezone offset)

### UI → DB (`beforeSave`)

1. Parses user input using `inputFormat` (tries both with and without offset)
2. Interprets it in `displayTimeZone` (unless offset provided)
3. Converts to `serverTimeZone`
4. Stores as UNIX timestamp or DATETIME string

---

### Automatic Timezone Fallback

If you don't explicitly set `displayTimeZone` in the behavior, it will automatically pick up your application's timezone from `Yii::$app->formatter->timeZone` or `Yii::$app->timeZone`. This ensures consistency across your application without extra configuration.

```php
// In common/config/main.php
'timeZone' => 'Asia/Tokyo',

// In your Model - no displayTimeZone needed!
[
    'class' => DateTimeBehavior::class,
    'attributes' => ['created_at'],
],
```

---

## Helper Methods

### `toTimestamp($attribute)`

Returns a UTC-normalized UNIX timestamp for a given attribute, regardless of whether the attribute currently holds a raw database value or a localized UI string.

```php
$timestamp = $model->getBehavior('dt')->toTimestamp('created_at');
```

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

---

## Testing

Run the test suite via Composer:

```bash
composer test
```
**Note:** You may see "Risky" test warnings in the output. This is expected behavior due to Yii2's global error handler manipulation during tests and does not indicate test failure.

---

## License

MIT

---

## Roadmap

- [x] PHPUnit test suite
- [x] Support for additional database formats (date, time, custom)
- [ ] Read-only / write-only modes
- [ ] Integration with popular date/time widgets
- [ ] Support for batch operations (e.g. `updateAll()`)
