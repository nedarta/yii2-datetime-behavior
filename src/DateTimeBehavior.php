<?php

namespace nedarta\behaviors;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;

/**
 * DateTime Behavior for converting between unix timestamps and formatted dates.
 * Fixed to handle 'time' and 'date' formats as Wall-Clock values to avoid 2h shifts.
 *
 * @property ActiveRecord $owner
 */
class DateTimeBehavior extends Behavior
{
	/**
	 * Static helper to get 'now' in the database format for a specific model.
	 */
	public static function now(string|ActiveRecord $model): string|int
	{
		$instance = is_string($model) ? new $model() : $model;
		$behavior = null;

		foreach ($instance->getBehaviors() as $b) {
			if ($b instanceof self) {
				$behavior = $b;
				break;
			}
		}

		if (!$behavior) {
			return time();
		}

		return $behavior->toDbValue('now');
	}

	public array $attributes = [];
	public string $dbFormat = 'unix'; // unix|datetime|date|time
	public string $inputFormat = 'Y-m-d H:i';
	public string|\Closure|null $displayTimeZone = null;
	public string $serverTimeZone = 'UTC';

	private array $_originalValues = [];

	public function events(): array
	{
		return [
			ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
			ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
			ActiveRecord::EVENT_AFTER_VALIDATE => 'afterValidate',
			ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
			ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
		];
	}

	/**
	 * DB (UTC) → UI (Localized)
	 */
	public function afterFind(): void
	{
		foreach ($this->attributes as $attribute) {
			$value = $this->owner->{$attribute};

			if ($this->isEmpty($value)) {
				continue;
			}

			$dt = $this->createDateTimeFromDb($value);
			if ($dt === false) {
				continue;
			}

			// Convert to user's display timezone
			$dt->setTimezone($this->getDisplayTimeZone());

			/**
			 * For 'date' format: Show as "2024-01-01 02:00 +02:00" (wall-clock at midnight UTC becomes local time)
			 * For 'time' format: Show as time only without offset
			 * For point-in-time formats (unix/datetime): Show with offset
			 */
			if ($this->dbFormat === 'date') {
				$format = $this->inputFormat . ' P';
			} elseif ($this->dbFormat === 'time') {
				$format = $this->inputFormat;
			} else {
				// unix or datetime
				$format = $this->inputFormat . ' P';
			}

			$this->owner->{$attribute} = $dt->format($format);
		}
	}

	public function beforeValidate(): void
	{
		foreach ($this->attributes as $attribute) {
			$this->_originalValues[$attribute] = $this->owner->{$attribute};
		}
	}

	public function afterValidate(): void
	{
		if ($this->owner->hasErrors()) {
			foreach ($this->attributes as $attribute) {
				if ($this->owner->hasErrors($attribute)) {
					$this->owner->{$attribute} = $this->_originalValues[$attribute] ?? null;
				}
			}
		}
		$this->_originalValues = [];
	}

	public function beforeSave(): void
	{
		foreach ($this->attributes as $attribute) {
			$this->owner->{$attribute} = $this->toDbValue($this->owner->{$attribute});
		}
	}

	public function normalize(array $data): array
	{
		foreach ($data as $attribute => $value) {
			if (in_array($attribute, $this->attributes)) {
				$data[$attribute] = $this->toDbValue($value);
			}
		}
		return $data;
	}

	public function toDbValue(mixed $value): string|int|null
	{
		if ($this->isEmpty($value)) {
			return null;
		}

		if ($value === 'now') {
			$dt = new \DateTime('now', new \DateTimeZone('UTC'));
			return $this->formatForDb($dt);
		}

		if ($this->isDbFormat($value)) {
			return $value;
		}

		$dt = $this->parseInput($value);
		if ($dt === false) {
			return $value;
		}

		return $this->formatForDb($dt);
	}

	/**
	 * Convert an attribute value to Unix timestamp.
	 * Handles various input formats: UI format, display format with offset, and raw timestamp.
	 *
	 * @param string $attribute The attribute name
	 * @return int|null The Unix timestamp, or null if empty
	 */
	public function toTimestamp(string $attribute): int|null
	{
		$value = $this->owner->{$attribute};

		if ($this->isEmpty($value)) {
			return null;
		}

		// If already a numeric timestamp, return as-is
		if (is_numeric($value) && !str_contains((string)$value, '-') && !str_contains((string)$value, ':')) {
			return (int)$value;
		}

		// Parse the input value
		$dt = $this->parseInput($value);
		if ($dt === false) {
			return null;
		}

		// Return the Unix timestamp
		return $dt->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();
	}

	protected function formatForDb(\DateTime $dt): string|int
	{
		/**
		 * Always convert to UTC before formatting, regardless of the format.
		 * This ensures consistent database storage of timestamps.
		 */
		$dt->setTimezone(new \DateTimeZone($this->serverTimeZone));

		if ($this->dbFormat === 'unix') {
			return $dt->getTimestamp();
		}

		$format = match ($this->dbFormat) {
			'datetime' => 'Y-m-d H:i:s',
			'date'     => 'Y-m-d',
			'time'     => 'H:i:s',
			default    => $this->dbFormat,
		};

		return $dt->format($format);
	}

	protected function parseInput(mixed $value): \DateTime|false
	{
		$value = (string)$value;
		$tz = $this->getDisplayTimeZone();

		// Try with timezone offset (from afterFind)
		$dt = \DateTime::createFromFormat('!' . $this->inputFormat . ' P', $value, $tz);

		if ($dt === false) {
			// Try without offset (from user input)
			$dt = \DateTime::createFromFormat('!' . $this->inputFormat, $value, $tz);
		}

		return $dt;
	}

	protected function createDateTimeFromDb(mixed $value): \DateTime|false
	{
		$tz = new \DateTimeZone($this->serverTimeZone);

		if ($this->dbFormat === 'unix') {
			if (!is_numeric($value)) return false;
			$dt = new \DateTime('now', $tz);
			$dt->setTimestamp((int)$value);
			return $dt;
		}

		// Logic for 'time': Use current date context to ensure DST is correct
		if ($this->dbFormat === 'time') {
			$dt = new \DateTime('today', $tz);
			$parts = explode(':', (string)$value);
			if (count($parts) >= 2) {
				$dt->setTime((int)$parts[0], (int)$parts[1], (int)($parts[2] ?? 0));
				return $dt;
			}
			return false;
		}

		$format = match ($this->dbFormat) {
			'datetime' => 'Y-m-d H:i:s',
			'date'     => 'Y-m-d',
			default    => $this->dbFormat,
		};

		// Use ! to reset fields not in format to 1970-01-01
		return \DateTime::createFromFormat('!' . $format, (string)$value, $tz);
	}

	protected function isDbFormat(mixed $value): bool
	{
		if ($this->dbFormat === 'unix') {
			return is_int($value) || (is_string($value) && ctype_digit($value));
		}

		if (!is_string($value)) return false;

		$format = match ($this->dbFormat) {
			'datetime' => 'Y-m-d H:i:s',
			'date'     => 'Y-m-d',
			'time'     => 'H:i:s',
			default    => $this->dbFormat,
		};

		$dt = \DateTime::createFromFormat('!' . $format, $value);
		return $dt && $dt->format($format) === $value;
	}

	protected function getDisplayTimeZone(): \DateTimeZone
	{
		$tz = $this->displayTimeZone;
		if ($tz === null) {
			$tz = Yii::$app->formatter->timeZone ?? Yii::$app->timeZone;
		}
		if ($tz instanceof \Closure) {
			$tz = call_user_func($tz);
		}
		return new \DateTimeZone($tz);
	}

	protected function isEmpty(mixed $value): bool
	{
		if ($this->dbFormat === 'unix' && $value === 0) return false;
		return $value === null || $value === '';
	}
}